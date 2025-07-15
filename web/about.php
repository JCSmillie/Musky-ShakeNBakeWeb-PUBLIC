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
<div style="max-width: 600px; margin: 0 auto; text-align: left;">
  <p>The MUSKY Suite/tools/system/etc whatever you would like to call it is dedicated to memory of my Dad, George E. Smillie (1955-2024.)  An avid bowler and Family man my Dad's true passion was food and he enjoyed helping people.  He honed these skills working for G.C. Murphy Co early in his life washing dishes and making hoagies.  With hardwork and great attention to quality he worked his way up the ranks to eventually Manager and gained a reputation through out the company for fixing loss leading restaurants inside G. C. Murphy Co and Murphy Mart stores and making them profitable again.  In 1995 he was transfered back to Western PA where he would find the location where he would open his own restarant, Smillie's Family Restaurant, in Mt. Plesant, PA November 1995.  The restaurant would be the defining family project for years and as George's personal family grew through marriages and grandchildren he kept his nose to the grind stone.  Always trying to make great food at decent prices.  George felt that a family man, like himself, should be able to go out to Dinner with the family and not morgage your house to do it.  Cheap food, but not cheap quaility and still doing things his way up until he passed in April 2024.</p>

  <p>The underpins of this project were started while I sat with my dad, George, in his final months.  A combination of keeping my mind busy with so much unknown and trying to be helpful to Jennifer Czyzewski who held down my day job at Gateway School District while I spend that last time with him. </p>

  <p>Its hard to put a whole person into a short paragraph or two so understand that regardless there is so so much more to my dad then I could ever fit to type here.  As a parting thought I also want you to know that my mom often called my dad Musky enderingly.  Our mascot, Musky, is the flag bearer of this project.</p>

  <p>Also to the ones crawling under desks, tracing cables in ceilings, and answering helpdesk tickets while juggling firmware updates — this one's for you.</p>

  <p>Your effort matters. Your uptime is seen. And this project exists because of the standards you uphold, even when no one’s watching.</p>
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
