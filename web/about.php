<?php
// about.php
// Internal Gateway Device Manager Health and Version Page

include 'config.php';

// ===============================
// Check Last Problem Report
// ===============================
$problemLog = rtrim($LOG_PATH, '/') . '/problem_reports_log.txt';
$lastProblemReport = 'No reports logged yet.';

if (file_exists($problemLog)) {
    $lines = file($problemLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($lines)) {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], '[') === 0) {
                $lastProblemReport = $lines[$i];
                break;
            }
        }
    }
}

// Detect PHP Version
$phpVersion = phpversion();
$serverIP = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>About - MUSKY Device Manager</title>
<style>
  body {
    font-family: Arial, sans-serif;
    background-color: #f4f4f4;
    padding: 20px;
    color: #333;
    text-align: center;
  }
  h1 {
    color: #054905;
  }
  table {
    width: 100%;
    max-width: 600px;
    margin: 20px auto;
    border-collapse: collapse;
    text-align: left;
  }
  td {
    padding: 10px;
    border-bottom: 1px solid #ddd;
  }
  .status-ok {
    color: green;
    font-weight: bold;
  }
  .status-warning {
    color: orange;
    font-weight: bold;
  }
  .status-error {
    color: red;
    font-weight: bold;
  }
  a {
    display: inline-block;
    margin-top: 20px;
    font-size: 18px;
    color: #007BFF;
    text-decoration: none;
  }
  a:hover {
    text-decoration: underline;
  }
  #mascot {
    margin-top: 40px;
    width: 150px;
    transition: transform 0.5s;
  }

  /* Bounce animation keyframes */
  @keyframes mascotBounce {
    0%, 100% { transform: translateY(0); }
    30% { transform: translateY(-20px); }
    50% { transform: translateY(0); }
    70% { transform: translateY(-10px); }
  }

  .bounce {
    animation: mascotBounce 0.5s ease;
  }
</style>
</head>
<body>

<h1>About MUSKY Device Manager</h1>

<table>
<tr><td>Version:</td><td>MUSKY Device Manager v1.0</td></tr>
<tr><td>PHP Version:</td><td><?php echo htmlspecialchars($phpVersion); ?></td></tr>
<tr><td>Server IP Address:</td><td><?php echo htmlspecialchars($serverIP); ?></td></tr>
<tr><td>Slack Notifications:</td>
<td class="<?php echo ($ENABLE_SLACK ? 'status-ok' : 'status-warning'); ?>">
<?php echo ($ENABLE_SLACK ? 'Enabled' : 'Disabled'); ?>
</td></tr>
<tr><td>Last Problem Report:</td><td><?php echo htmlspecialchars($lastProblemReport); ?></td></tr>
</table>

<div style="text-align: center; margin-top: 20px; font-family: sans-serif; color: #777;">
    <?php echo DEDICATION_MESSAGE; ?>
</div>

<p><a href="/index.php">Return to Device Manager</a></p>

<!-- Mascot image -->
<img id="mascot" src="mascot.png" alt="Mascot">

<!-- Mascot Bounce JavaScript -->
<script>
// Automatically bounce mascot every 5 seconds
function triggerMascotBounce() {
  const mascot = document.getElementById('mascot');
  mascot.classList.add('bounce');
  setTimeout(() => mascot.classList.remove('bounce'), 500);
}

// Set interval to bounce mascot
setInterval(triggerMascotBounce, 5000);
</script>

</body>
</html>
