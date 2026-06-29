<?php
// login.php - MUSKY Auth Interface (Unified Auth w/ Tool Permissions)

session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/MuskyFirstTimeSetup.php';
require_once __DIR__ . '/../../Functions/MuskyUserMariaSync.php';
require_once __DIR__ . '/../../Functions/NoraConfigStore.php';

if (!function_exists('musky_safe_return_path')) {
    function musky_safe_return_path(?string $candidate, string $default = '/index.php'): string {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return $default;
        }

        if ($candidate[0] !== '/') {
            return $default;
        }

        if (preg_match('#^//#', $candidate)) {
            return $default;
        }

        return $candidate;
    }
}

if (!function_exists('musky_local_login_identity_candidates')) {
    function musky_local_login_identity_candidates(string $username, array $legacyUser = []): array
    {
        $username = trim($username);
        $candidates = [];

        $legacyEmail = strtolower(trim((string)($legacyUser['email'] ?? '')));
        if ($legacyEmail !== '') {
            $candidates[] = $legacyEmail;
        }

        foreach (musky_identity_username_lookup_emails($username) as $candidate) {
            $candidate = strtolower(trim((string)$candidate));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        if ($username !== '') {
            $candidates[] = $username;
        }

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('musky_local_login_fetch_user_row')) {
    function musky_local_login_fetch_user_row(PDO $pdo, string $identity): ?array
    {
        $identity = trim($identity);
        if ($identity === '') {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM musky_users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$identity]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('musky_local_login_resolve_identity')) {
    function musky_local_login_resolve_identity(PDO $pdo, string $username, array $legacyUser = []): array
    {
        $candidates = musky_local_login_identity_candidates($username, $legacyUser);

        foreach ($candidates as $candidate) {
            $row = musky_local_login_fetch_user_row($pdo, $candidate);
            if (is_array($row)) {
                return [
                    'identity' => (string)($row['email'] ?? $candidate),
                    'row' => $row,
                ];
            }
        }

        return [
            'identity' => $candidates[0] ?? trim($username),
            'row' => null,
        ];
    }
}

$now = date('[Y-m-d H:i:s]');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$errors = [];
$returnTarget = musky_safe_return_path($_GET['return'] ?? '/index.php');

$authDir = __DIR__;
$noLocalFile = "$authDir/.NoLocalUserLogin";
$maintenanceFile = "$authDir/.Maintenance";
$maintenanceDbFlag = musky_nora_maintenance_mode_enabled('LOGIN_GATE');

// -----------------------------------------------------------------------------
// Handle Maintenance Lockout
// -----------------------------------------------------------------------------
if (file_exists($maintenanceFile) || $maintenanceDbFlag) {
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
            // Reload the page every 30 seconds so either the legacy file flag
            // or the DB-backed hold can clear without manual action.
            async function checkMaintenance() {
                window.location.reload();
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

if (musky_first_time_access_required()) {
    header('Location: /setup/first-time-access.php?return=' . urlencode($returnTarget));
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
// Determine if local-login store is available or blocked
// -----------------------------------------------------------------------------
$authStore = musky_user_store_primary_pdo();
$authStoreBackend = musky_user_store_backend_name($authStore);

if ($authStoreBackend === 'mysql') {
    musky_user_mysql_sync_all_from_sqlite();
}

$db_available = $authStore instanceof PDO
    && musky_user_store_has_table($authStore, 'users')
    && musky_user_store_has_column($authStore, 'users', 'password');
$local_login_disabled = file_exists($noLocalFile);

// -----------------------------------------------------------------------------
// Authentication logic
// -----------------------------------------------------------------------------
if ($db_available && !$local_login_disabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $pdo = $authStore;

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['username'] = $username;
            $_SESSION['logged_in'] = true;
            $_SESSION['check_in'] = true;
            $_SESSION['last_active'] = time();
            $_SESSION['was_logged'] = true;

            $resolved = musky_local_login_resolve_identity($pdo, $username, is_array($user) ? $user : []);
            $resolvedIdentity = trim((string)($resolved['identity'] ?? $username));
            $musky = is_array($resolved['row'] ?? null) ? $resolved['row'] : null;

            if ($musky === null) {
                $now = date('Y-m-d H:i:s');
                $defaultTheme = trim((string)($user['theme'] ?? 'light-mode')) ?: 'light-mode';
                $insert = $pdo->prepare("
                    INSERT INTO musky_users (
                        email, role, building, allowed_tools, theme,
                        created_at, updated_at, source_db, last_synced_at
                    )
                    VALUES (?, 'legacy', 'unknown', 'YOUR_DEVICE', ?, ?, ?, 'legacy', ?)
                ");
                $insert->execute([$resolvedIdentity, $defaultTheme, $now, $now, $now]);
                $musky = musky_local_login_fetch_user_row($pdo, $resolvedIdentity);
            }

            try {
                if (musky_user_store_has_column($pdo, 'musky_users', 'last_login_at')) {
                    $touch = $pdo->prepare("UPDATE musky_users SET last_login_at = CURRENT_TIMESTAMP WHERE LOWER(email) = LOWER(?)");
                    $touch->execute([$resolvedIdentity]);
                }
            } catch (Throwable $e) {
                // Leave legacy login untouched if this convenience timestamp
                // cannot be updated.
            }

            $musky = is_array($musky) ? $musky : musky_local_login_fetch_user_row($pdo, $resolvedIdentity);
            if (!is_array($musky)) {
                $musky = [
                    'email' => $resolvedIdentity,
                    'role' => 'legacy',
                    'building' => 'unknown',
                    'allowed_tools' => 'YOUR_DEVICE',
                    'theme' => trim((string)($user['theme'] ?? 'light-mode')) ?: 'light-mode',
                ];
            }
            $musky['username'] = $username;
            $_SESSION['musky_user'] = $musky;

            // Keep the fallback SQLite store alive when MySQL is primary, and
            // keep the MariaDB mirror alive when SQLite is primary.
            $muskySyncRow = $musky;
            unset($muskySyncRow['username']);
            if ($authStoreBackend === 'mysql') {
                musky_user_sqlite_upsert_oldskool_user_row($user);
                if (is_array($muskySyncRow)) {
                    musky_user_sqlite_upsert_user_row($muskySyncRow);
                }
            } else {
                musky_user_mysql_sync_oldskool_user_row($user);
                musky_user_mysql_sync_by_email($resolvedIdentity, $muskySyncRow ?: null);
            }

            musky_activity_log_login('local', [
                'login_type' => 'local',
                'redirect'   => $returnTarget,
            ]);

            $redirect = $returnTarget;
            header("Location: $redirect");
            exit;
        } else {
            $errors[] = "Invalid credentials.";
        }
    } catch (Exception $e) {
        error_log('[auth/login] Local login failed: ' . $e->getMessage());
        $errors[] = "Internal error. Please try again or use Google login.";
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
    <a href="/SSO/Google/login.php?return=<?= urlencode($returnTarget) ?>"
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
