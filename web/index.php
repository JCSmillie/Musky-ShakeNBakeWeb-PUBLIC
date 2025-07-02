<?php
session_start();

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

<!DOCTYPE html>
<html>
<head>
    <title>Device Management Hub</title>
    <link rel="stylesheet" href="theme.css">
    <style>
        .nav-links {
            margin: 20px 0;
        }

        .nav-links a {
            display: block;
            padding: 10px;
            margin: 5px 0;
            font-size: 1.1rem;
            text-decoration: none;
            background: #4CAF50;
            color: white;
            border-radius: 5px;
            text-align: center;
        }

        .nav-links a:hover {
            background: #45a049;
        }

        .top-right {
            position: fixed;
            top: 10px;
            right: 10px;
            text-align: right;
        }

        .theme-select {
            margin-bottom: 10px;
            font-size: 1rem;
            padding: 6px;
            border-radius: 5px;
        }

        .action-button {
            width: auto;
        }

        .action-button.red {
            background-color: red;
        }

        .action-button.red:hover {
            background-color: darkred;
        }
    </style>
    <script>
        function setTheme(theme) {
            document.body.className = theme;
            fetch("", {
                method: "POST",
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: "theme=" + encodeURIComponent(theme)
            });
        }

        function onThemeChange(selectElement) {
            setTheme(selectElement.value);
        }

        function logoff() {
            const returnUrl = encodeURIComponent("<?= $_SERVER['REQUEST_URI'] ?>");
            const loginPage = "<?= rtrim($TWO_FA_PORTAL_URL, '/') ?>/login.php?return=" + returnUrl;
            fetch("logout.php").then(() => {
                window.location.href = loginPage;
            });
        }

        function showProblemBox(source) {
            const desc = prompt("Describe the problem you're experiencing:");
            if (desc) {
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "submit_problem.php", true);
                xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
                xhr.send("source_page=" + encodeURIComponent(source)
                    + "&description=" + encodeURIComponent(desc)
                    + "&browser_info=" + encodeURIComponent(navigator.userAgent)
                    + "&username=" + encodeURIComponent("<?= $username ?>"));
                alert("Thanks for your report!");
            }
        }
    </script>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

<div class="top-right">
    <select class="theme-select" onchange="onThemeChange(this)">
        <option value="light-mode" <?= $theme === 'light-mode' ? 'selected' : '' ?>>Light</option>
        <option value="dark-mode" <?= $theme === 'dark-mode' ? 'selected' : '' ?>>Dark</option>
        <option value="musky-mode" <?= $theme === 'musky-mode' ? 'selected' : '' ?>>Musky</option>
        <option value="gator-time-mode" <?= $theme === 'gator-time-mode' ? 'selected' : '' ?>>Gator Time</option>
    </select>
    <br>
    <button class="action-button" onclick="logoff()">Log Off</button>
</div>

<div class="main">
    <h1>📱 Device Management Hub</h1>

    <div class="nav-links">
        <a href="Loaners/index.php">📋 Loaner Management</a>
        <a href="DeviceManager/index.php">🔧 Device Management</a>
    </div>

    <hr>

    <button class="action-button red" onclick="showProblemBox('index.php')">PROBLEM</button>
</div>

<!-- Mascot Image -->
<img src="mascot.png" alt="Mascot" class="corner-image">

</body>
</html>

<?php
$db->close(); // Always close your database connection
?>