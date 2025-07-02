<?php
// Prevent direct browser access
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit("Access denied.");
}

require_once __DIR__ . '/config.php';

if (!empty($TWO_FA_CONFIG_PATH) && file_exists($TWO_FA_CONFIG_PATH)) {
    require_once $TWO_FA_CONFIG_PATH;

    if (!empty($ENABLE_2FA)) {
        $returnUrl = $_SERVER['REQUEST_URI']; // includes path and query string
        $returnParam = urlencode($returnUrl);
        $loginPage = rtrim($TWO_FA_PORTAL_URL, '/') . "/login.php?return=$returnParam";

        // Start session and populate REMOTE_USER
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_SESSION['username'])) {
            $_SERVER['REMOTE_USER'] = $_SESSION['username']; // 🛠 simulate Apache-style tracking
        }

        $now = date('[Y-m-d H:i:s]');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $logPath = $SESSION_LOG_PATH ?? '/tmp/2fa_session_log.txt';

        // Not logged in yet? Redirect to 2FA portal
        if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            file_put_contents($logPath, "$now LOGIN REDIRECT: $ip to $loginPage\n", FILE_APPEND);
            header("Location: $loginPage");
            exit;
        }

        // Session timeout check
        $lastActive = $_SESSION['last_active'] ?? 0;
        if (time() - $lastActive > ($SESSION_TIMEOUT ?? 1800)) {
            file_put_contents($logPath, "$now SESSION EXPIRED: {$_SESSION['username']} ($ip)\n", FILE_APPEND);
            session_destroy();
            header("Location: $loginPage");
            exit;
        }

        // First-time login session logging
        if (empty($_SESSION['was_logged'])) {
            $_SESSION['was_logged'] = true;
            file_put_contents($logPath, "$now LOGIN SUCCESS: {$_SESSION['username']} ($ip)\n", FILE_APPEND);
        }

        // Refresh session timestamp
        $_SESSION['last_active'] = time();
    }
}
