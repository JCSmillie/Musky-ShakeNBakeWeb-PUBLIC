<?php
// login.php - MUSKY Auth Interface (Unified Auth w/ Tool Permissions)

session_start();
require_once __DIR__ . '/../config.php';

$logPath = $SESSION_LOG_PATH ?? '/tmp/2fa_session_log.txt';
$now = date('[Y-m-d H:i:s]');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$errors = [];

$authDir = __DIR__;
$noLocalFile = "$authDir/.NoLocalUserLogin";
$maintenanceFile = "$authDir/.Maintenance";

// -----------------------------------------------------------------------------
// Handle Maintenance Lockout
// -----------------------------------------------------------------------------
if (file_exists($maintenanceFile)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>🚧 MUSKY Maintenance</title>
        <link rel="stylesheet" href="../theme.css">
        <style>
            body {
                font-family: 'Segoe UI', sans-serif;
                background: repeating-linear-gradient(
                    45deg,
                    #222 0,
                    #222 20px,
                    #f9c900 20px,
                    #f9c900 40px
                );
                color: #fff;
                text-align: center;
                margin: 0;
                padding: 5rem;
            }
            .banner {
                background: rgba(0,0,0,0.85);
                display: inline-block;
                padding: 2rem 3rem;
                border: 4px solid #f9c900;
                border-radius: 12px;
                box-shadow: 0 0 25px rgba(0,0,0,0.9);
            }
            h1 {
                font-size: 2.5rem;
                margin-bottom: 1rem;
                color: #f9c900;
                text-shadow: 0 0 10px rgba(255,255,0,0.6);
            }
            p { font-size: 1.1rem; margin: 0.6rem 0; }
            button {
                margin-top: 1.5rem;
                padding: 0.7rem 1.5rem;
                font-size: 1rem;
                font-weight: bold;
                background-color: #f9c900;
                color: #000;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s ease;
            }
            button:hover { background-color: #ffdf2e; transform: scale(1.05); }
            .checking { margin-top: 0.5rem; font-size: 0.9rem; color: #ddd; opacity: 0.8; }
        </style>
        <script>
            // Auto-check for maintenance flag removal every 30 seconds
            async function checkMaintenance() {
                try {
                    const test = await fetch('./.Maintenance?nocache=' + Date.now(), { method: 'HEAD', cache: 'no-store' });
                    if (test.status === 404) { window.location.reload(); }
                } catch (e) { console.log("Maintenance check failed:", e); }
            }
            setInterval(checkMaintenance, 30000);
        </script>
    </head>
    <body class="musky-mode">
        <div class="banner">
            <h1>🚧 MUSKY IS DOWN FOR MAINTENANCE 🚧</h1>
            <p>We’re performing system updates and will be back shortly.</p>
            <p>Please check back later.</p>
            <button onclick="window.location.reload()">⟳ Refresh Now</button>
            <div class="checking">(Auto-checking every 30 seconds)</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -----------------------------------------------------------------------------
// Handle session-expired message
// -----------------------------------------------------------------------------
$expired_msg = '';
if (isset($_GET['expired']) && $_GET['expired'] == '1') {
    $expired_msg = urldecode($_GET['msg'] ?? 'Session expired due to inactivity.');
}

// -----------------------------------------------------------------------------
// Determine if local DB is available or blocked
// -----------------------------------------------------------------------------
$db_available = isset($SQLITE_PATH) && file_exists($SQLITE_PATH);
$local_login_disabled = file_exists($noLocalFile);
$dbPath = $db_available ? $SQLITE_PATH : null;

// -----------------------------------------------------------------------------
// Authentication logic
// -----------------------------------------------------------------------------
if ($db_available && !$local_login_disabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $pdo = new PDO("sqlite:$dbPath");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("PRAGMA table_info(users)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        if (!in_array('password', $columnNames)) {
            $errors[] = "❌ DB missing 'password' column.";
            throw new Exception("Missing 'password' column.");
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['username'] = $username;
            $_SESSION['logged_in'] = true;
            $_SESSION['check_in'] = true;
            $_SESSION['last_active'] = time();
            $_SESSION['was_logged'] = true;

            $check = $pdo->prepare("SELECT * FROM musky_users WHERE email = ?");
            $check->execute([$username]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $insert = $pdo->prepare("INSERT INTO musky_users (email, role, building, allowed_tools) VALUES (?, 'legacy', 'unknown', 'YOUR_DEVICE')");
                $insert->execute([$username]);
            }

            $load = $pdo->prepare("SELECT * FROM musky_users WHERE email = ?");
            $load->execute([$username]);
            $musky = $load->fetch(PDO::FETCH_ASSOC);
            $_SESSION['musky_user'] = $musky;

            $redirect = $_GET['return'] ?? '/musky/';
            header("Location: $redirect");
            exit;
        } else {
            $errors[] = "Invalid credentials.";
        }
    } catch (Exception $e) {
        $errors[] = "Internal error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MUSKY Login</title>
    <link rel="stylesheet" href="../theme.css">
    <style>
        body.musky-mode {
            background: linear-gradient(135deg, #b8ecb8, #d0f5d0, #c8e8c8);
            color: #054905;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 3rem;
        }

        .welcome-header {
            text-align: center;
            font-family: 'Segoe UI', sans-serif;
            font-size: 1.6rem;
            color: #054905;
            margin-bottom: 1.5rem;
            text-shadow: 0 1px 0 rgba(255,255,255,0.6);
        }

        /* Frosted Glass Login Form */
        form, .google-login {
            max-width: 420px;
            width: 90%;
            margin: 1rem auto;
            padding: 1.5rem;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            backdrop-filter: blur(12px) saturate(140%);
            border: 1px solid rgba(255,255,255,0.4);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        h2 {
            text-align: center;
            color: #054905;
            margin-bottom: 1rem;
            text-shadow: 0 1px 0 rgba(255,255,255,0.6);
        }

        label { font-weight: bold; color: #054905; }

        input[type=text], input[type=password] {
            width: 100%;
            padding: 0.6rem;
            margin: 0.4rem 0;
            border-radius: 8px;
            border: 1px solid #a3cfa3;
            background: rgba(255,255,255,0.8);
            font-size: 1rem;
            color: #054905;
        }

        input[type=submit] {
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 8px;
            width: 100%;
            padding: 0.7rem;
            font-size: 1rem;
            margin-top: 0.8rem;
            cursor: pointer;
            transition: background 0.3s;
        }
        input[type=submit]:hover { background-color: #45a049; }

        .google-login {
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            width: 100%;
            margin-top: 1.2rem;
        }
        .google-button {
            display: inline-block;
            background: linear-gradient(180deg, #3fa34d, #2e7d32);
            color: white;
            text-align: center;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            width: 80%;
            padding: 0.7rem;
            box-shadow: 0 3px 8px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            margin: 0 auto;
        }
        .google-button:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }

        .expired-banner {
            background-color: rgba(255, 243, 205, 0.9);
            color: #856404;
            border: 1px solid #ffeeba;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 1rem;
            text-align: center;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .musky-footer {
            text-align: center;
            font-size: 0.85rem;
            color: #054905;
            opacity: 0.8;
            margin-top: 2.5rem;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        /* Frosted lockout overlay */
        .disabled-overlay::after {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            backdrop-filter: blur(10px) brightness(1.05);
            background: rgba(255,255,255,0.55);
            border-radius: 16px;
            z-index: 0;
        }

        .frosted-message {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            font-weight: bold;
            font-size: 1rem;
            color: #054905;
            background: rgba(255,255,255,0.9);
            padding: 1rem;
            border-radius: 12px;
            width: 85%;
            z-index: 2;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            opacity: 0;
            animation: fadeIn 0.5s ease forwards;
        }

        /* Mascot */
        .corner-image {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 4in;
            opacity: 0.9;
            animation: mascotBounce 5s ease-in-out infinite;
            transition: transform 0.3s ease;
        }
        .corner-image:hover { transform: scale(1.05); }

        /* === Paw Reveal Animation === */
        #intro-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #000;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeToBrown 2.5s ease-in-out forwards;
        }
        #paw-reveal {
            width: 50vw;
            opacity: 0;
            transform: scale(3);
            filter: sepia(100%) hue-rotate(25deg) saturate(200%) brightness(0.8)
                    drop-shadow(0 10px 15px rgba(30,20,10,0.8));
            animation: pawZoom 3s ease-in-out forwards;
        }
        @keyframes fadeIn { to { opacity: 1; } }
        @keyframes fadeToBrown {
            0% { background-color: #000; }
            50% { background-color: #3b2c1d; }
            100% { background-color: #3b2c1d; opacity: 0; }
        }
        @keyframes pawZoom {
            0% { opacity: 0; transform: scale(3); }
            30% { opacity: 1; transform: scale(2); }
            70% { opacity: 1; transform: scale(1); filter: blur(0); }
            100% { opacity: 0; transform: scale(1); filter: blur(4px); }
        }

        /* Green pulse for Google button when local login disabled */
        @keyframes googlePulse {
            0%   { box-shadow: 0 0 0 0 rgba(63,163,77,0.45); }
            70%  { box-shadow: 0 0 0 12px rgba(63,163,77,0); }
            100% { box-shadow: 0 0 0 0 rgba(63,163,77,0); }
        }
        .google-pulse { animation: googlePulse 2.5s infinite; }
    </style>
</head>
<body class="musky-mode">

<h1 class="welcome-header">
    Welcome to MUSKY Students, Help Desk Staff, Techs, and Faculty!
</h1>

<!-- Cinematic Paw Reveal Overlay -->
<div id="intro-overlay">
    <img id="paw-reveal" src="../MuskyPaw.png" alt="Musky Paw">
</div>

<form method="POST" <?= (!$db_available || $local_login_disabled) ? 'class="disabled-overlay"' : '' ?>>
    <h2>Login to MUSKY</h2>

    <?php if (!empty($expired_msg)): ?>
        <div class="expired-banner">⚠️ <?= htmlspecialchars($expired_msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error"><?php foreach ($errors as $e): ?><div>⚠️ <?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <label for="username">Username:</label>
    <input name="username" type="text" required <?= (!$db_available || $local_login_disabled) ? 'disabled' : '' ?>>

    <label for="password">Password:</label>
    <input name="password" type="password" required <?= (!$db_available || $local_login_disabled) ? 'disabled' : '' ?>>

    <input type="submit" value="Log In" <?= (!$db_available || $local_login_disabled) ? 'disabled' : '' ?>>

    <?php if (!$db_available || $local_login_disabled): ?>
        <div class="frosted-message">
            🔒 <?= $local_login_disabled ? 'Local User Login has been disabled.' : 'Local login unavailable on this system.' ?>
            <div class="info-tip">
                ℹ️ <?= $local_login_disabled
                    ? 'This mode is enforced by the .NoLocalUserLogin flag in /web/auth/.'
                    : 'Local authentication database is not accessible on this system.' ?>
            </div>
        </div>
    <?php endif; ?>
</form>

<div class="google-login">
    <p>or</p>
    <a href="/SSO/Google/login.php"
       class="google-button <?= (!$db_available || $local_login_disabled) ? 'google-pulse' : '' ?>">
       Login with Google
    </a>
</div>

<footer class="musky-footer">
    All data in this system is property of Gateway School District.<br>
    Software, methods, and systems © SmillieWare.
</footer>

<!-- Musky Mascot / Logo in Corner -->
<img src="../mascot.png" alt="Musky Mascot" class="corner-image">

<script>
window.addEventListener('load', () => {
    setTimeout(() => {
        const overlay = document.getElementById('intro-overlay');
        if (overlay) overlay.style.display = 'none';
    }, 3200);
});
</script>

</body>
</html>