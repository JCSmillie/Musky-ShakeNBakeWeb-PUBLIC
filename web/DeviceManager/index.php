<?php
require_once '../check_access.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SQLITE_PATH', '/etc/httpd/2Fa/MUSKY2FA.db');
$TWO_FA_PORTAL_URL = $TWO_FA_PORTAL_URL ?? '/2fa';

$username = $_SESSION['username'] ?? '';

// Fallback theme
$theme = 'light-mode';

// Shared SQLite connection
$db = new SQLite3(SQLITE_PATH);
$db->busyTimeout(3000);

// Load user's theme if possible
if ($username !== '') {
    $stmt = $db->prepare("SELECT theme FROM users_2fa WHERE username = :username");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        if (!empty($row['theme'])) {
            $theme = $row['theme'];
        }
    }
    if ($result) $result->finalize();
}

// Handle theme update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme']) && $username !== '') {
    $newTheme = $_POST['theme'];
    $stmt = $db->prepare("UPDATE users_2fa SET theme = :theme WHERE username = :username");
    $stmt->bindValue(':theme', $newTheme, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->execute();
    $_SESSION['theme'] = $newTheme;
    $db->close();
    echo json_encode(['success' => true]);
    exit;
}

?>

<?php
// index.php
// MUSKY Device Manager main page
// --------------------------------

// Include shared config settings
include '../config.php';

// Enable detailed PHP error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===================
// Setup Variables
// ===================
$showSidebar = false;
$parsedData = [];
$deviceStolen = false;
    if (!empty($parsedData) && is_array($parsedData)) {
        $_SESSION['last_lookup'] = $parsedData;
    }
$rawInput = '';
$debugOutput = '';
$mainOutput = '';
$lastCommand = '';

// ===================
// Setup Logging Function
// ===================
// Logs actions and events with timestamp, IP, and username
function write_log($message) {
    global $LOG_PATH; // Get log path from config
    $logfile = rtrim($LOG_PATH, '/') . '/device_manager_log.txt';
    $timestamp = date('[Y-m-d H:i:s]');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $authUser = $_SERVER['REMOTE_USER'] ?? 'UNKNOWN_USER';
    $fullMessage = "{$timestamp} IP: {$ipAddress} User: {$authUser} | {$message}" . PHP_EOL;
    file_put_contents($logfile, $fullMessage, FILE_APPEND | LOCK_EX);
}
?>
<!DOCTYPE html>

<html lang="en">
<head>
<script>
  window.USERID = <?= json_encode($parsedData['USERID'] ?? '') ?>;

function toggleModules() {
    const container = document.getElementById("moduleContainer");
    const output = document.getElementById("moduleOutput");
    const icon = document.getElementById("moduleToggleIcon");

    if (container.style.display === "none") {
        container.style.display = "block";
        icon.textContent = "?";
        if (!modulesLoaded) {
            fetch("load_modules.php")
                .then(res => res.text())
                .then(html => { output.innerHTML = html; modulesLoaded = true; })
                .catch(() => { output.innerHTML = "?? Failed to load modules."; });
        }
    } else {
        container.style.display = "none";
        icon.textContent = "?";
    }
}
let modulesLoaded = false;

// ========== Load Individual Module on Demand ==========
function loadModule(name) {
  const output = document.getElementById("moduleDetails");
  output.innerHTML = "? Loading " + name + "...";

  fetch("run_modules.php?name=" + encodeURIComponent(name))
    .then(res => res.text())
    .then(html => {
      output.innerHTML = html;

      // ? Execute any inline scripts from the loaded module
      const scripts = output.querySelectorAll("script");
      scripts.forEach(script => {
        const newScript = document.createElement("script");
        if (script.src) {
          newScript.src = script.src;
        } else {
          newScript.textContent = script.textContent;
        }
        document.body.appendChild(newScript);
        document.body.removeChild(newScript);
      });
    })
    .catch(() => {
      output.innerHTML = "?? Failed to load module.";
    });
}

</script>
<link rel="icon" type="image/png" href="../musky_favicon.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GSD - MUSKY - Device Manager</title>

<!-- Theme Stylesheet -->
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">

<!-- Screenshot Library -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<!-- Style for PROBLEM Button -->
<style>
#problemButton {
  position: fixed;
  bottom: 10px;
  left: 10px;
  background-color: red;
  color: white;
  padding: 12px 20px;
  font-weight: bold;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  z-index: 10000;
}

.urgent-alert {
  background-color: red;
  color: white;
  font-size: 2em;
  font-weight: bold;
  text-align: center;
  padding: 1em;
  margin: 1em 0;
  border: 4px solid black;
  animation: pulse 1s infinite;
}
@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.05); }
  100% { transform: scale(1); }
}

</style>
</head>

<body>
<div class="top-controls" style="display:flex;justify-content:space-between;align-items:center;margin:1em 0;">
    <button onclick="window.location.href='../index.php'" style="background:#ccc;padding:0.5em 1em;border:none;border-radius:5px;cursor:pointer;">?? Return to Launch</button>
    <div id="refresh-timer" style="font-weight:bold;">Last updated: just now</div>
</div>


<!-- Theme Switcher -->
<div class="theme-switcher">
  <select id="theme-select" onchange="changeTheme(this.value)">
    <option value="light-mode">Light</option>
    <option value="dark-mode">Dark</option>
    <option value="musky-mode">Musky</option>
    <option value="gator-time-mode">GatorTime</option>
  </select>
</div>

<!-- Mascot Icon -->
<img id="mascot" src="../mascot.png" alt="Mascot" class="corner-image">

<!-- Main Content -->
<div class="content">
<div class="main">

<h1>MUSKY Device Manager</h1>

<!-- Search Form -->
<form method="post" id="searchForm">
  <input type="text" name="user_input" id="user_input" placeholder="Enter Asset Tag"
    value="<?php echo isset($_POST['user_input']) ? htmlspecialchars($_POST['user_input']) : ''; ?>" required>
  <button type="submit" class="action-button">Search!</button>
</form>
<?php if ($deviceStolen): ?>
  <div class="urgent-alert">CONTACT MR. SMILLIE ASAP!!!!</div>
<?php endif; ?>

<?php
// ===================
// Process Form or Direct URL Lookup
// ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['assettag'])) {

    if (!empty($_POST['user_input'])) {
        $rawInput = $_POST['user_input'];
    } elseif (!empty($_POST['previous_input'])) {
        $rawInput = $_POST['previous_input'];
    } elseif (!empty($_GET['assettag'])) {
        $rawInput = $_GET['assettag'];
    }

    $input = escapeshellarg($rawInput); // Sanitize

    if (!empty($rawInput)) {
        // Handle Action Buttons
        if (isset($_POST['button1'])) {
            $lastCommand = "$MOSBASIC_PATH ioswipe $input";
            $debugOutput = shell_exec($lastCommand . " 2>&1");
            write_log("Button Pressed: Wipe Device | Asset: {$rawInput} | Command: {$lastCommand} | Output: " . substr($debugOutput, 0, 500));
        } elseif (isset($_POST['lostmodeoff'])) {
            $lastCommand = "$MOSBASIC_PATH lostmodeoff $input";
            $debugOutput = shell_exec($lastCommand . " 2>&1");
            write_log("Button Pressed: Lost Mode OFF | Asset: {$rawInput} | Command: {$lastCommand}");
        } elseif (isset($_POST['lostmodeon'])) {
            $lastCommand = "$MOSBASIC_PATH lostmodeon $input";
            $debugOutput = shell_exec($lastCommand . " 2>&1");
            write_log("Button Pressed: Lost Mode ON | Asset: {$rawInput} | Command: {$lastCommand}");
        } elseif (isset($_POST['assign_button'])) {
            $lastCommand = "$MOSBASIC_PATH iosassign $input";
            $debugOutput = shell_exec($lastCommand . " 2>&1");
            write_log("Button Pressed: Assign Device | Asset: {$rawInput} | Command: {$lastCommand}");
        } elseif (isset($_POST['restart_button'])) {
            $lastCommand = "$MOSBASIC_PATH restart $input";
            $debugOutput = shell_exec($lastCommand . " 2>&1");
            write_log("Button Pressed: Restart Device | Asset: {$rawInput} | Command: {$lastCommand}");
        } elseif (isset($_POST['play_sound'])) {
            $lastCommand = "$MOSBASIC_PATH annoy $input";
            $debugOutput = shell_exec($lastCommand . " 2>&1");
            write_log("Button Pressed: Play Sound | Asset: {$rawInput} | Command: {$lastCommand}");
        } elseif (isset($_POST['show_location'])) {
            if (!empty($_POST['device_serial'])) {
                $serialInput = escapeshellarg($_POST['device_serial']);
                $lastCommand = "$LOCATION_SCRIPT_PATH/LocationWebLink.sh $serialInput";
                $debugOutput = shell_exec($lastCommand . " 2>&1");
                write_log("Button Pressed: Show Location | Asset: {$rawInput} | Command: {$lastCommand}");
                if (preg_match('/https?:\/\/\S+/', $debugOutput, $matches)) {
                    $locationLink = $matches[0];
                    echo "<script>window.open(" . json_encode($locationLink) . ", '_blank');</script>";
                }
            }
        }

        // Always refresh device info
        $mainOutput = shell_exec("$MOSBASIC_PATH getinfomini $input 2>&1; echo ExitCode:$?");
        if (preg_match('/ExitCode:(\d+)/', $mainOutput, $matches)) {
            $exitCode = (int)$matches[1];
            $mainOutput = preg_replace('/ExitCode:\d+/', '', $mainOutput);
            if ($exitCode === 0) {
                $showSidebar = true;
            }
        }

        // Parse Key-Value device info
        if (!empty($rawInput) && !empty($mainOutput)) {
        $lines = explode("\n", trim($mainOutput));
        $_SESSION["parsed_lines"] = $lines;
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $parsedData[trim($key)] = trim($value);
            }
        }
        }
    }
}

// ===================
// Display Device Info
// ===================
if (!empty($parsedData)) {
    write_log("Page Loaded | Asset: {$parsedData['ASSETTAG']} | Serial: {$parsedData['DeviceSerialNumber']}");
    $_SESSION['last_lookup'] = $parsedData;
    echo "<h2>" . htmlspecialchars($parsedData['ASSETTAG'] ?? 'Unknown Asset') . "</h2>";

    if (!empty($parsedData['USERID'])) {
        echo "<p>Username: " . (strtoupper($parsedData['USERID']) === 'UNASSIGNED' ? "&lt;&lt;DEVICE UNASSIGNED&gt;&gt;" : htmlspecialchars($parsedData['USERID'])) . "</p>";
    }

    if (!empty($parsedData['LAST_IP_BEAT'])) {
        echo "<p>Last IP Address: " . ($parsedData['LAST_IP_BEAT'] === '65.254.21.222' ? "On Campus" : "Off Site") . "</p>";
    }

    if (!empty($parsedData['NEEDSOSUPDATE'])) {
        echo "<div class='attention'>UPDATE REQUIRED: " . htmlspecialchars($parsedData['NEEDSOSUPDATE']) . "</div>";
    } else {
        echo "<p>No Updates Needed.</p>";
    }

    if (!empty($parsedData['LASTCHECKINRAW'])) {
        $minutesSinceLastCheckIn = floor((time() - (int)$parsedData['LASTCHECKINRAW']) / 60);
        if ($minutesSinceLastCheckIn >= 2880) {
            echo "<div class='attention'>Device Last Check In: " . floor($minutesSinceLastCheckIn / 60) . " hours ago</div>";
        } elseif ($minutesSinceLastCheckIn >= 60) {
            echo "<p>Device Last Check In: " . floor($minutesSinceLastCheckIn / 60) . " hours ago</p>";
        } else {
            echo "<p>Device Last Check In: " . $minutesSinceLastCheckIn . " minutes ago</p>";
        }
    }

    // Detect STOLEN tag
    $tags = explode(',', $parsedData['TAGS'] ?? '');
    $tags = array_map('trim', array_map('strtoupper', $tags));
    if (in_array('STOLEN', $tags)) {
        $deviceStolen = true;
    }

// External tag decoder
    include 'decode_tags.php';
// ========== 3rd Party Modules ==========
        echo "<hr><h3>🛠️ 3rd Party Modules</h3>";
        $modFiles = glob(__DIR__ . "/Modules/*.php");
        if (!empty($modFiles)) {
            echo "<ul>";
            foreach ($modFiles as $modPath) {
                $modName = basename($modPath);
                echo "<li><a href='Modules/$modName' target='_blank'>$modName</a></li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No modules found in /Modules.</p>";
        }
        }
?>


</div> <!-- end main -->
<?php if ($showSidebar): ?>
<?php
// Normalize Lost Mode status for easy logic
$lostModeStatus = strtoupper(trim($parsedData['LOSTMODESTATUS'] ?? ''));
?>

<!-- Sidebar with action buttons -->
<div class="sidebar">
<?php if ($deviceStolen): ?>
<style>
.sidebar .action-button {
  background-color: black !important;
  color: white !important;
}
</style>
<?php endif; ?>
<h3>Actions</h3>

<!-- Action Form -->
<form method="post">
  <input type="hidden" name="previous_input" value="<?php echo htmlspecialchars($rawInput); ?>">
  <input type="hidden" name="device_serial" value="<?php echo htmlspecialchars($parsedData['DeviceSerialNumber'] ?? ''); ?>">

  <?php if (!$deviceStolen): ?>
<!-- Wipe Device -->
  <button class="action-button" name="button1" onclick="return confirm('Are you sure you want to Wipe the device?');">
    Wipe Device
  </button>

  <!-- Lost Mode Controls -->
  <?php
  if ($minutesSinceLastCheckIn >= 2880) {
      echo '<button class="action-button orange-button" disabled>Lost Mode (Unavailable)</button>';
  } else {
      if ($lostModeStatus === 'ENABLED') {
          echo '<button class="action-button" name="lostmodeoff">Disable Lost Mode</button>';
      } elseif ($lostModeStatus === 'DISABLED') {
          echo '<button class="action-button" name="lostmodeon">Enable Lost Mode</button>';
      } else {
          echo '<button class="action-button orange-button" disabled>Lost Mode Jammed</button>';
      }
  }
  ?>

  <!-- Assign Device if Unassigned -->
  <?php if (!empty($parsedData['USERID']) && strtoupper($parsedData['USERID']) === 'UNASSIGNED'): ?>
    <button class="action-button" name="assign_button" onclick="return confirm('Assign this device?');">
      Assign Device
    </button>
  <?php endif; ?>

  <!-- Restart iPad -->
  <?php
  if ($lostModeStatus === 'ENABLED') {
      echo '<button class="action-button orange-button" disabled>Restart iPad (Unavailable)</button>';
  } else {
      echo '<button class="action-button" name="restart_button" onclick="return confirm(\'Are you sure you want to restart this iPad?\');">
      Restart iPad
      </button>';
  }
  ?>

  <!-- Play Sound and Show Location -->
  <?php
  if ($lostModeStatus === 'ENABLED' && $minutesSinceLastCheckIn < 2880) {
      echo '<button class="action-button" name="play_sound">Play Sound</button>';
      echo '<button class="action-button" name="show_location">Show Location</button>';
  }
  ?>

  <?php endif; ?>
<!-- Refresh Lookup -->
  <button class="action-button" type="button" onclick="forceRefresh()">
    Look Up Again
  </button>

  <!-- Toggle Debug Output -->
  <button class="action-button" type="button" onclick="handleDebugClick()">
    DEBUG
  </button>
</form>
<?php if ($deviceStolen): ?>
  <div class="urgent-alert">CONTACT MR. SMILLIE ASAP!!!!</div>
<?php endif; ?>
</div> <!-- end sidebar -->
<?php endif; ?>

<!-- Hidden Debug Output Pane -->
<div id="debugPane" style="display:none;"></div>

<!-- PROBLEM Button -->
<button id="problemButton" onclick="openProblemReport()">PROBLEM</button>

<!-- Pass PHP variables to JavaScript -->
<script>
// Last executed shell command
const lastCommand = <?php echo json_encode($lastCommand); ?>;

// Last debug output (either from command or device info)
const lastDebugOutput = <?php echo json_encode($debugOutput ?: $mainOutput); ?>;

// Device identifiers for Problem Reports
const deviceSerialNumber = "<?php echo htmlspecialchars($parsedData['DeviceSerialNumber'] ?? 'UNKNOWN'); ?>";
const deviceAssetTag = "<?php echo htmlspecialchars($parsedData['ASSETTAG'] ?? 'UNKNOWN'); ?>";
</script>

<!-- JavaScript Section -->
<script>
// ========== Theme Switching ==========
function changeTheme(theme) {
  document.body.className = theme;
  localStorage.setItem('selectedTheme', theme);
}

// ========== Force Page Refresh ==========
function forceRefresh() {
  document.getElementById('searchForm').submit();
}

// ========== Toggle Debug Pane ==========
function handleDebugClick() {
  const debugPane = document.getElementById('debugPane');

  if (debugPane.style.display === 'block') {
    debugPane.style.display = 'none';
    debugPane.innerHTML = '';
    localStorage.removeItem('debug-open');
  } else {
    debugPane.style.display = 'block';
    debugPane.innerHTML =
      "<div style='color:red;font-weight:bold;'>COMMAND SENT -> " + lastCommand + "</div>" +
      "<pre>" + lastDebugOutput + "</pre>";
    localStorage.setItem('debug-open', 'yes');
  }

  triggerMascotBounce();
}

// ========== Capture Problem Report ==========
function openProblemReport() {
  html2canvas(document.body).then(canvas => {
    const screenshot = canvas.toDataURL('image/png');
    const feedback = prompt("Please describe the problem:");
    if (feedback !== null) {
      sendProblemReport(screenshot, feedback);
    }
  });
}

// ========== Send Problem Report ==========
function sendProblemReport(screenshot, feedback) {
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "../submit_problem.php", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

  xhr.onload = function() {
    if (xhr.status === 200) {
      alert("Thank you! Your report has been submitted.");
    } else {
      alert("Problem sending report: " + xhr.responseText);
    }
  };

  xhr.onerror = function() {
    alert("An error occurred during problem submission.");
  };

  xhr.send(
    "screenshot=" + encodeURIComponent(screenshot) +
    "&feedback=" + encodeURIComponent(feedback) +
    "&serial=" + encodeURIComponent(deviceSerialNumber) +
    "&assettag=" + encodeURIComponent(deviceAssetTag)
  );
}


// ========== Mascot Bounce Animation ==========
function triggerMascotBounce() {
  const mascot = document.getElementById('mascot');
  mascot.classList.add('bounce');
  setTimeout(() => mascot.classList.remove('bounce'), 500);
}

// ========== Window Load Setup ==========
window.onload = function() {
  const savedTheme = localStorage.getItem('selectedTheme') || 'light-mode';
  document.body.className = savedTheme;
  const themeSelect = document.getElementById('theme-select');
  if (themeSelect) {
    themeSelect.value = savedTheme;
  }

  const sidebarButtons = document.querySelectorAll('.sidebar .action-button');
  sidebarButtons.forEach(button => {
    button.addEventListener('click', triggerMascotBounce);
  });

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('assettag')) {
    document.getElementById('user_input').value = urlParams.get('assettag');
  }

  if (localStorage.getItem('debug-open') === 'yes' || urlParams.has('debug')) {
    handleDebugClick();
  }

  const searchForm = document.getElementById('searchForm');
  if (urlParams.has('debug')) {
    const actionURL = window.location.pathname + '?debug=1';
    searchForm.setAttribute('action', actionURL);
  }
};
</script>


<script>
let secondsElapsed = 0;
let timerInterval = null;

function updateTimerDisplay() {
    const el = document.getElementById('refresh-timer');
    const minutes = Math.floor(secondsElapsed / 60);
    const seconds = secondsElapsed % 60;
    el.textContent = `Last updated: ${minutes}:${seconds.toString().padStart(2, '0')} ago`;
}

function startRefreshTimer() {
    secondsElapsed = 0;
    updateTimerDisplay();
    clearInterval(timerInterval);
    timerInterval = setInterval(() => {
        secondsElapsed++;
        updateTimerDisplay();
    }, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
    startRefreshTimer();
    const lookupButton = document.querySelector('#lookup-again');
    if (lookupButton) {
        lookupButton.addEventListener('click', () => {
            startRefreshTimer();
        });
    }
});
</script>
</body>

</html>