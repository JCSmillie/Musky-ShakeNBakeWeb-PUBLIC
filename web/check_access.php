<?php
// ============================================================================
// MUSKY Access Control Middleware  (Google SSO + Legacy Internal Login Only)
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../Functions/MuskyConfig.php';

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: no-referrer");

if (!function_exists('musky_favicon_markup')) {
    function musky_favicon_markup(): string {
        return '<link rel="icon" type="image/png" href="/musky_favicon.png">' . "\n";
    }
}

if (!function_exists('musky_inject_favicon_into_html')) {
    function musky_inject_favicon_into_html(string $buffer): string {
        if ($buffer === '') {
            return $buffer;
        }

        if (stripos($buffer, '</head>') === false) {
            return $buffer;
        }

        if (stripos($buffer, '<html') === false) {
            return $buffer;
        }

        if (preg_match('/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\']/i', $buffer)) {
            return $buffer;
        }

        return preg_replace('/<\/head>/i', musky_favicon_markup() . '</head>', $buffer, 1) ?? $buffer;
    }
}

if (!defined('MUSKY_FAVICON_OB_ACTIVE')) {
    define('MUSKY_FAVICON_OB_ACTIVE', true);
    ob_start('musky_inject_favicon_into_html');
}

// ============================================================================
// Dev Harness: bypass SSO completely on the configured dev host suffix
// ============================================================================
$muskyDevHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$muskyDevHost = preg_replace('/:\d+$/', '', $muskyDevHost) ?? $muskyDevHost;
$devHostSuffix = musky_dev_host_suffix();
if ($devHostSuffix !== '' && str_ends_with($muskyDevHost, $devHostSuffix)) {

    if (!empty($_SESSION['musky_user'])) {
        return; // Already faked in
    }

    $_SESSION['musky_user'] = [
        'email'         => 'dev@local.host',
        'name'          => 'Local Dev Tester',
        'allowed_tools' => 'ALL_TOOLS'
    ];

    return;
}

// ============================================================================
// Idle Timeout Enforcement
// ============================================================================
$idleTimeout = $SESSION_TIMEOUT ?? musky_root_config_int('session.timeout_seconds', 3600);

if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > $idleTimeout) {
    session_unset();
    session_destroy();
    $msg = urlencode("Session expired due to inactivity. Please log in again.");
    header("Location: /auth/login.php?expired=1&msg=$msg");
    exit;
}

$_SESSION['last_active'] = time();

// ============================================================================
// Always define the login page
// ============================================================================
$returnUrl = urlencode($_SERVER['REQUEST_URI']);
$loginPage = "/auth/login.php?return=$returnUrl";

// ============================================================================
// Determine login types
// ============================================================================
$is_internal_login = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$is_sso_login = (
    !empty($_SESSION['musky_user']['email']) &&
    filter_var($_SESSION['musky_user']['email'], FILTER_VALIDATE_EMAIL)
);

// ============================================================================
// Require a login (SSO or Legacy Internal)
// ============================================================================
if (!$is_internal_login && !$is_sso_login) {

    // Avoid redirect loop if already ON the login page
    if (basename($_SERVER['PHP_SELF']) !== basename($loginPage)) {
        header("Location: $loginPage");
        exit;
    }
}

// ============================================================================
// Set REMOTE_USER for downstream scripts
// ============================================================================
if (!empty($_SESSION['username'])) {
    $_SERVER['REMOTE_USER'] = $_SESSION['username'];
} elseif (!empty($_SESSION['musky_user']['email'])) {
    $_SERVER['REMOTE_USER'] = $_SESSION['musky_user']['email'];
}

musky_activity_log_page_view([
    'query' => $_GET ?? [],
]);

// ============================================================================
// ADMIN HELPER
// ============================================================================
function musky_is_admin(): bool {
    /**
     * Why this helper exists:
     * - Several admin/template endpoints call `musky_is_admin()` directly.
     * - We keep the logic here centralized so we can evolve role tiers without
     *   having to hand-edit every endpoint gate.
     *
     * Updated policy intent:
     * - Preserve legacy behavior (`DEVICE_MANAGER`, `ALL_TOOLS`).
     * - Support modern admin-level tools (`ADMIN_PANEL`,
     *   `HDK-SUPERVISOR-ADMIN`).
     */
    $allowedRaw = $_SESSION['musky_user']['allowed_tools'] ?? '';
    if ($allowedRaw === '' || $allowedRaw === null) {
        return false;
    }

    // Normalize allowed_tools from either historical CSV storage or
    // array-based session payloads.
    if (is_array($allowedRaw)) {
        $allowed = array_filter(array_map(
            static fn($v) => strtoupper(trim((string)$v)),
            $allowedRaw
        ));
    } else {
        $allowed = array_filter(array_map(
            static fn($v) => strtoupper(trim((string)$v)),
            explode(',', (string)$allowedRaw)
        ));
    }

    $adminGateTools = [
        'ALL_TOOLS',
        'ADMIN_PANEL',
        'HDK-SUPERVISOR-ADMIN',
    ];

    foreach ($adminGateTools as $toolCode) {
        if (in_array($toolCode, $allowed, true)) {
            return true;
        }
    }

    return false;
}
