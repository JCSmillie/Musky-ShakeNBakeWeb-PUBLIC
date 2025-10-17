<?php
// ============================================================================
// MUSKY Access Control Middleware (Updated for SSO)
// -----------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: no-referrer");

require_once __DIR__ . '/config.php';
if (!empty($TWO_FA_CONFIG_PATH) && file_exists($TWO_FA_CONFIG_PATH)) {
    require_once $TWO_FA_CONFIG_PATH;
}

$ENABLE_2FA = isset($ENABLE_2FA) && strtolower($ENABLE_2FA) === 'true';

// -----------------------------------------------------------------------------
// Idle timeout
// -----------------------------------------------------------------------------
$idleTimeout = $SESSION_TIMEOUT ?? 1800;
if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > $idleTimeout) {
    session_unset();
    session_destroy();

    // Redirect to login with a friendly timeout message
    $msg = urlencode("Session expired due to inactivity. Please log in again.");
    header("Location: /auth/login.php?expired=1&msg=$msg");
    exit;
}
$_SESSION['last_active'] = time();

// -----------------------------------------------------------------------------
// Redirect target
// -----------------------------------------------------------------------------
$returnUrl = urlencode($_SERVER['REQUEST_URI']);
$loginPage = ($ENABLE_2FA && isset($TWO_FA_PORTAL_URL))
    ? rtrim($TWO_FA_PORTAL_URL, '/') . "/login.php?return=$returnUrl"
    : "/auth/login.php?return=$returnUrl";

// -----------------------------------------------------------------------------
// Allow login via:
// - Legacy internal session (`logged_in`)
// - OR Musky SSO (`musky_user` with valid email)
// -----------------------------------------------------------------------------
$is_internal_login = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$is_sso_login = !empty($_SESSION['musky_user']['email']) && filter_var($_SESSION['musky_user']['email'], FILTER_VALIDATE_EMAIL);

// Enforce login (with 2FA check if applicable)
if ($ENABLE_2FA) {
    if (empty($_SESSION['check_in']) || (!$is_internal_login)) {
        header("Location: $loginPage");
        exit;
    }
} else {
    if (!$is_internal_login && !$is_sso_login) {
        if (basename($_SERVER['PHP_SELF']) !== basename($loginPage)) {
            header("Location: $loginPage");
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// Always populate REMOTE_USER for downstream scripts
// -----------------------------------------------------------------------------
if (!empty($_SESSION['username'])) {
    $_SERVER['REMOTE_USER'] = $_SESSION['username'];
} elseif (!empty($_SESSION['musky_user']['email'])) {
    $_SERVER['REMOTE_USER'] = $_SESSION['musky_user']['email'];
}