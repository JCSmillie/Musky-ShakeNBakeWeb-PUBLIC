<?php
// ============================================================================
// LoggedInUserPrefs.php
// ----------------------------------------------------------------------------
// Unified handler for retrieving logged-in user preferences + test harness.
// All UI pages MUST use this instead of touching SQLite/session directly.
//
// Features:
//   ✓ Production: uses real Musky session + SQLite themes
//   ✓ Local Dev: auto-detect the configured dev host suffix and activate the
//     dev harness
//   ✓ Future-proof: can be migrated to MariaDB without any UI changes
//   ✓ Provides single stable API: musky_get_logged_in_user_prefs()
//
// ----------------------------------------------------------------------------
// API:
//
//   require_once __DIR__ . '/../Functions/LoggedInUserPrefs.php';
//   $prefs = musky_get_logged_in_user_prefs();
//   $theme = $prefs['theme'];
//   $allowed = $prefs['allowed_tools'];
//   $email = $prefs['email'];
//
// ----------------------------------------------------------------------------
// Internal caching: request-local only
// ============================================================================
require_once __DIR__ . '/MuskyConfig.php';
require_once __DIR__ . '/MuskyUserMariaSync.php';

$GLOBALS['_musky_user_pref_cache'] = null;


/**
 * ENTRY POINT — returns full preference map for the logged-in user.
 *
 * @param string|null $override_email (optional)
 * @return array {
 *    email: string
 *    theme: string
 *    allowed_tools: array
 *    default_loaner_pool: string
 *    display_name: string
 * }
 */
function musky_get_logged_in_user_prefs(string $override_email = null): array
{
    // Return cached version if called twice
    if (!is_null($GLOBALS['_musky_user_pref_cache'])) {
        return $GLOBALS['_musky_user_pref_cache'];
    }

    // Always ensure session exists
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // -------------------------------------------------------------
    // 0. Detect Local Dev Mode
    // -------------------------------------------------------------
    $is_local_dev =
        str_ends_with(strtolower($_SERVER['HTTP_HOST'] ?? ''), musky_dev_host_suffix()) ||
        ($_SESSION['TESTMODE_SQLITE_PATH'] ?? false) ||
        (isset($_COOKIE['musky_test_user']));

    if ($is_local_dev) {
        _musky_apply_test_harness();   // imports fake session, cookies, SQLite tap
    }

    // -------------------------------------------------------------
    // 1. Determine email
    // -------------------------------------------------------------
    $session_email = $_SESSION['musky_user']['email'] ?? '';
    $email         = $override_email ?: $session_email;

    // -------------------------------------------------------------------------
    // DEFAULT PREFS — now includes all fields Musky pages expect
    // -------------------------------------------------------------------------
    $prefs = [
        'email'         => $email ?: '',

        // These fields get overridden if found in session
        'first_name'    => '',
        'last_name'     => '',
        'full_name'     => '',
        'display_name'  => '',     // canonical merged name
        'photo_url'     => null,
        'role'          => '',
        'building'      => '',

        // feature values
        'theme'               => 'musky-mode',
        'allowed_tools'       => [],
        'default_loaner_pool' => '',
        'is_local_dev'        => $is_local_dev,
    ];

    // -------------------------------------------------------------
    // 2. Allowed tools from session (canonical source)
    // -------------------------------------------------------------
    $allowed_raw = $_SESSION['musky_user']['allowed_tools'] ?? '';
    if (is_string($allowed_raw)) {
        $prefs['allowed_tools'] = array_filter(array_map('trim', explode(',', $allowed_raw)));
    }

    // -------------------------------------------------------------
    // 3. Populate extended session fields (first_name, photo_url, etc.)
    // -------------------------------------------------------------
    $u = $_SESSION['musky_user'] ?? [];

    // first / last / full
    $prefs['first_name'] = $u['first_name'] ?? '';
    $prefs['last_name']  = $u['last_name']  ?? '';
    $prefs['full_name']  = $u['full_name']  ?? (
        trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))
    );

    // Display name preference order
    $prefs['display_name'] =
        $u['full_name'] ??
        (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ??
        $email;

    // Photo
    $prefs['photo_url'] = $u['photo_url'] ?? null;

    // Role and building
    $prefs['role']     = $u['role']     ?? '';
    $prefs['building'] = $u['building'] ?? '';

    // Theme should honor the current authenticated session first. This lets
    // Musky keep the correct look even when the old SQLite file is missing and
    // auth has already populated a full user row from MariaDB.
    if (!empty($u['theme'])) {
        $prefs['theme'] = (string)$u['theme'];
    }
    if (!empty($u['default_loaner_pool'])) {
        $prefs['default_loaner_pool'] = (string)$u['default_loaner_pool'];
    }

    // -------------------------------------------------------------
    // 4. Load persisted theme / loaner prefs from the active user store
    // -------------------------------------------------------------
    try {
        $db = musky_user_store_primary_pdo();
        if ($db && $email) {
            $stmt = $db->prepare("SELECT theme, default_loaner_pool FROM musky_users WHERE email=?");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!empty($row['theme'])) {
                    $prefs['theme'] = (string)$row['theme'];
                }
                if (!empty($row['default_loaner_pool'])) {
                    $prefs['default_loaner_pool'] = (string)$row['default_loaner_pool'];
                }
            }
        }
    } catch (Throwable $e) {
        // silently ignore: fallback theme already applied
    }

    // Cache & return
    $GLOBALS['_musky_user_pref_cache'] = $prefs;
    return $prefs;
}


/**
 * Return only theme for given email.
 */
function musky_get_user_theme(string $email): string
{
    $prefs = musky_get_logged_in_user_prefs($email);
    return $prefs['theme'] ?? 'light-mode';
}


/**
 * INTERNAL — activate local dev harness for the configured dev suffix
 * and other test-mode surfaces.
 */
function _musky_apply_test_harness(): void
{
    // ============================================================================
    // Dev/Test Harness Compatibility Layer
    // ============================================================================

    $isDevHost      = (php_uname('n') && str_contains(php_uname('n'), musky_dev_host_suffix()));
    $sessionActive  = (session_status() === PHP_SESSION_ACTIVE);

    // *** SURGICAL FIX — ensure this is always defined ***
    $test_lifetime = 60 * 60 * 24 * 7; // 7 days

    // Only apply session settings before session_start()
    if ($isDevHost && !$sessionActive) {

        // Can only change ini + cookie params BEFORE session_start()
        ini_set('session.gc_maxlifetime', (string)$test_lifetime);
        session_set_cookie_params([
            'lifetime' => $test_lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();

    } else {
        // Normal mode → ensure session exists
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    // --- Allow ?as=user@example.org override ------------------------
    $fakeEmail = $_GET['as'] ?? ($_SESSION['musky_user']['email'] ?? musky_root_config_string('dev.default_test_user_email', 'dev@example.org'));

    // Try to restore test user from cookie
    if (empty($_SESSION['musky_user'])) {
        if (!empty($_COOKIE['musky_test_user'])) {
            $cookieData = json_decode($_COOKIE['musky_test_user'], true);
            if (is_array($cookieData) && !empty($cookieData['email'])) {
                $_SESSION['musky_user'] = $cookieData;
            }
        }
    }

    // If still empty, create user from override
    if (empty($_SESSION['musky_user'])) {
        $_SESSION['musky_user'] = [
            'email'         => $fakeEmail,
            'name'          => '(Test User)',
            'full_name'     => '(Test User)',
            'allowed_tools' => 'MY_DEVICE,ALL_TOOLS,EXPERIMENTAL'
        ];
    }

    // Persist to cookie
    $cookiePayload = json_encode($_SESSION['musky_user']);
    setcookie('musky_test_user', $cookiePayload, time() + $test_lifetime, '/', '', false, true);

    // --- Fake SQLite for dev mode ----------------------------------
    if (empty($_SESSION['TESTMODE_SQLITE_PATH'])) {
        $_SESSION['TESTMODE_SQLITE_PATH'] = musky_root_config_string('dev.test_sqlite_path', '/tmp/musky_users.sqlite');
    }
    setcookie(
        'musky_test_sqlite',
        $_SESSION['TESTMODE_SQLITE_PATH'],
        time() + $test_lifetime,
        '/',
        '',
        false,
        true
    );
}
