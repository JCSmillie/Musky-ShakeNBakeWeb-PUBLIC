<?php
session_start();
require_once __DIR__ . '/../check_access.php';
require_once '../SSO/Google/config.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyUserMariaSync.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/tool_access_helpers.php';

$tool_required = 'ADMIN_PANEL';
$allowed = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? []);

if (!in_array($tool_required, $allowed) && !in_array('ALL_TOOLS', $allowed)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$tool_required}";
    exit;
}

if (!isset($_SESSION['musky_user'])) {
    http_response_code(403);
    echo "⛔ Access Denied — Not logged in.";
    exit;
}

// Load the current admin's theme via the shared preference helper so the page
// keeps working when theme storage eventually moves away from SQLite.
$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$syncFlash = $_SESSION['musky_admin_sync_flash'] ?? null;
unset($_SESSION['musky_admin_sync_flash']);

// Tool maps
// ---------
// We keep both maps in memory:
// - $tool_map: every known tool code (for labels / card chip display)
// - $group_tool_map: only codes allowed in the Group Access editor
//
// The map source now comes from tool_access_helpers.php metadata so policy
// changes are centralized and documented in one place.
$tool_map = require __DIR__ . '/tool_codes.php';
$group_tool_map = musky_admin_group_assignable_tool_map();
$all_tools = array_keys($tool_map);
$syncSummary = musky_user_mysql_sync_all_from_sqlite();
$dbBackend = musky_user_store_backend_name($db);
$sqliteSourcePath = musky_user_sqlite_source_path();
$csrfToken = musky_csrf_token('musky_admin');
$staffDomainSuffixes = musky_identity_domain_suffixes('staff_domains', ['example.org']);
$studentDomainSuffixes = musky_identity_domain_suffixes('student_domains', ['example.net']);
$hdkDomainSuffixes = musky_identity_domain_suffixes('hdk_domains', ['example.net']);
$restrictedDomainSuffixes = musky_identity_domain_suffixes('restricted_individual_domains', ['example.net']);

function musky_admin_domain_label(array $domains): string
{
    $clean = array_values(array_filter(array_map(
        static fn($value) => trim((string)$value),
        $domains
    ), static fn($value) => $value !== ''));

    return $clean ? implode(' + ', $clean) : '(none configured)';
}

/**
 * Group visibility persistence helpers
 * ------------------------------------
 * Admins can hide irrelevant directory groups from the Group Access list.
 * The hide state is stored in a tiny table so the preference survives refreshes
 * and can be shared by other admins.
 *
 * IMPORTANT:
 * - This feature only affects `web/admin/musky_admin.php` rendering.
 * - It does NOT delete group access data.
 * - It does NOT remove a group from Google / AD.
 */
function musky_admin_ensure_hidden_groups_table(PDO $pdo, string $dbBackend): void
{
    if ($dbBackend === 'mysql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS musky_hidden_groups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_email VARCHAR(255) NOT NULL,
                hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_musky_hidden_groups_email (group_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_hidden_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_email TEXT UNIQUE NOT NULL,
            hidden_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function musky_admin_fetch_hidden_groups(PDO $pdo): array
{
    $hidden = [];

    try {
        $rows = $pdo->query("SELECT group_email FROM musky_hidden_groups")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return $hidden;
    }

    foreach ($rows as $email) {
        $normalized = strtolower(trim((string)$email));
        if ($normalized !== '') {
            $hidden[$normalized] = true;
        }
    }

    return $hidden;
}

function musky_admin_hide_group(PDO $pdo, string $dbBackend, string $groupEmail): bool
{
    $groupEmail = strtolower(trim($groupEmail));
    if ($groupEmail === '') {
        return false;
    }

    try {
        if ($dbBackend === 'mysql') {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO musky_hidden_groups (group_email, hidden_at)
                VALUES (?, NOW())
            ");
            $stmt->execute([$groupEmail]);
        } else {
            $stmt = $pdo->prepare("
                INSERT OR IGNORE INTO musky_hidden_groups (group_email, hidden_at)
                VALUES (?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$groupEmail]);
        }
    } catch (Throwable $e) {
        return false;
    }

    return true;
}

function musky_admin_unhide_group(PDO $pdo, string $groupEmail): bool
{
    $groupEmail = strtolower(trim($groupEmail));
    if ($groupEmail === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM musky_hidden_groups WHERE group_email = ?");
        $stmt->execute([$groupEmail]);
    } catch (Throwable $e) {
        return false;
    }

    return true;
}

function musky_admin_redirect_preserving_group_toggle(string $showHidden): void
{
    $showHidden = $showHidden === '1' ? '1' : '0';
    $target = $showHidden === '1'
        ? "musky_admin.php?show_hidden_groups=1#group-access-section"
        : "musky_admin.php#group-access-section";
    header("Location: {$target}");
    exit;
}

try {
    musky_admin_ensure_hidden_groups_table($db, $dbBackend);
} catch (Throwable $e) {
    // Non-fatal by design:
    // if visibility-table provisioning fails, the rest of Admin should still
    // load rather than hard-failing all user/group administration.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('musky_admin');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_import') {
    $_SESSION['musky_admin_sync_flash'] = musky_user_mysql_sync_all_from_sqlite();
    header("Location: musky_admin.php");
    exit;
}

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $uname = trim($_POST['new_username']);
    $pword = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    $email = trim($_POST['new_email']);
    $muskyIdentity = $email !== '' ? $email : $uname;
    $newUserTheme = trim($_POST['new_theme']) ?: 'light-mode';

    if ($uname && $_POST['new_password']) {
        $db->prepare("INSERT INTO users (username, password, email, theme) VALUES (?, ?, ?, ?)")
           ->execute([$uname, $pword, $email, $newUserTheme]);

        if ($dbBackend === 'mysql') {
            $now = date('Y-m-d H:i:s');
            $db->prepare("
                INSERT INTO musky_users (
                    email, role, building, allowed_tools, theme,
                    created_at, updated_at, source_db, last_synced_at
                )
                VALUES (?, 'legacy', 'unknown', 'YOUR_DEVICE', ?, ?, ?, 'legacy', ?)
                ON DUPLICATE KEY UPDATE
                    theme = VALUES(theme),
                    updated_at = VALUES(updated_at),
                    source_db = VALUES(source_db),
                    last_synced_at = VALUES(last_synced_at)
            ")->execute([$muskyIdentity, $newUserTheme, $now, $now, $now]);

            musky_user_sqlite_upsert_oldskool_user_row([
                'username' => $uname,
                'password' => $pword,
                'email'    => $email,
                'theme'    => $newUserTheme,
            ]);
            musky_user_sqlite_upsert_user_row([
                'email'         => $muskyIdentity,
                'role'          => 'legacy',
                'building'      => 'unknown',
                'allowed_tools' => 'YOUR_DEVICE',
                'theme'         => $newUserTheme,
            ]);
        } else {
            $db->prepare("
                INSERT OR IGNORE INTO musky_users (email, role, building, allowed_tools, theme, created_at)
                VALUES (?, 'legacy', 'unknown', 'YOUR_DEVICE', ?, CURRENT_TIMESTAMP)
            ")->execute([$muskyIdentity, $newUserTheme]);

            musky_user_mysql_sync_oldskool_user_row([
                'username' => $uname,
                'password' => $pword,
                'email'    => $email,
                'theme'    => $newUserTheme,
            ]);
            musky_user_mysql_sync_by_email($muskyIdentity);
        }
    }
    header("Location: musky_admin.php");
    exit;
}

// Handle user update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && isset($_POST['action'])) {
    $email = $_POST['email'];

    if ($_POST['action'] === 'delete') {
        $db->prepare("DELETE FROM musky_users WHERE email = ?")->execute([$email]);
        if ($dbBackend === 'mysql') {
            musky_user_sqlite_delete_user($email);
        } else {
            musky_user_mysql_delete_user($email);
        }
        header("Location: musky_admin.php");
        exit;
    }

    // Enforce user-assignment policy server-side.
    //
    // WHY this matters:
    // Even though the UI hides forbidden choices, browsers can still post a
    // forged request. Sanitizing the submitted tool list prevents policy
    // bypass through hand-edited POST bodies.
    $submittedUserTools = $_POST['tools'] ?? [];
    if (!is_array($submittedUserTools)) {
        $submittedUserTools = [];
    }
    $sanitizedUserTools = musky_admin_sanitize_tool_selection($submittedUserTools, 'user', $email);
    $tools = implode(',', $sanitizedUserTools);
    $rowPayload = [
        $_POST['role'] ?? 'student',
        $_POST['building'] ?? 'unknown',
        $tools,
        $_POST['full_name'] ?? '',
        $_POST['first_name'] ?? '',
        $_POST['last_name'] ?? '',
        $_POST['org_unit'] ?? '',
        $_POST['title'] ?? '',
        $_POST['department'] ?? '',
        $_POST['location'] ?? '',
        isset($_POST['is_suspended']) ? 1 : 0,
        $_POST['photo_url'] ?? '',
        $_POST['theme'] ?? 'light-mode',
        $email
    ];
    $db->prepare("
        UPDATE musky_users SET
            role = ?, building = ?, allowed_tools = ?, 
            full_name = ?, first_name = ?, last_name = ?, org_unit = ?, title = ?, 
            department = ?, location = ?, is_suspended = ?, photo_url = ?, theme = ?
        WHERE email = ?
    ")->execute($rowPayload);

    if ($dbBackend === 'mysql') {
        musky_user_sqlite_upsert_user_row([
            'email'         => $email,
            'role'          => $_POST['role'] ?? 'student',
            'building'      => $_POST['building'] ?? 'unknown',
            'allowed_tools' => $tools,
            'full_name'     => $_POST['full_name'] ?? '',
            'first_name'    => $_POST['first_name'] ?? '',
            'last_name'     => $_POST['last_name'] ?? '',
            'org_unit'      => $_POST['org_unit'] ?? '',
            'title'         => $_POST['title'] ?? '',
            'department'    => $_POST['department'] ?? '',
            'location'      => $_POST['location'] ?? '',
            'is_suspended'  => isset($_POST['is_suspended']) ? 1 : 0,
            'photo_url'     => $_POST['photo_url'] ?? '',
            'theme'         => $_POST['theme'] ?? 'light-mode',
        ]);
    } else {
        musky_user_mysql_sync_by_email($email);
    }
    header("Location: musky_admin.php");
    exit;
}

// Handle group update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_group') {
    $group_email = $_POST['group_email'];

    // Enforce group-assignment policy server-side.
    //
    // Rule from project spec:
    // "NONE OF THE NEW TOOL CODES (start with +) can be added to groups."
    // In metadata terms, this is `group_assignable = false`.
    $submittedGroupTools = $_POST['group_tools'] ?? [];
    if (!is_array($submittedGroupTools)) {
        $submittedGroupTools = [];
    }
    $sanitizedGroupTools = musky_admin_sanitize_tool_selection($submittedGroupTools, 'group');
    $group_tools = implode(',', $sanitizedGroupTools);
    if ($dbBackend === 'mysql') {
        $upsert = $db->prepare("
            INSERT INTO musky_group_access (group_email, allowed_tools, source_db, last_synced_at)
            VALUES (?, ?, 'sqlite', NOW())
            ON DUPLICATE KEY UPDATE
                allowed_tools = VALUES(allowed_tools),
                source_db = VALUES(source_db),
                last_synced_at = VALUES(last_synced_at)
        ");
        $upsert->execute([$group_email, $group_tools]);
        musky_user_sqlite_upsert_group_access($group_email, $group_tools);
    } else {
        $upsert = $db->prepare("REPLACE INTO musky_group_access (group_email, allowed_tools) VALUES (?, ?)");
        $upsert->execute([$group_email, $group_tools]);
        musky_user_mysql_sync_group_access($group_email, $group_tools);
    }
    header("Location: musky_admin.php");
    exit;
}

// Handle group hide/unhide
// ------------------------
// These actions only control whether a group appears in the Group Access list.
// They do not modify musky_group_access rights directly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['hide_group', 'unhide_group'], true)) {
    $groupEmail = (string)($_POST['group_email'] ?? '');
    $returnShowHidden = (string)($_POST['return_show_hidden_groups'] ?? '0');

    if (($_POST['action'] ?? '') === 'hide_group') {
        musky_admin_hide_group($db, $dbBackend, $groupEmail);
    } else {
        musky_admin_unhide_group($db, $groupEmail);
    }

    musky_admin_redirect_preserving_group_toggle($returnShowHidden);
}

$users = [];
try {
    $users = $db->query("SELECT * FROM musky_users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $users = [];
}
if (empty($users)) {
    $users = musky_user_mysql_fetch_all_users();
}

// Load groups
$known_groups = [];
$group_json_path = '/tmp/google_user_groups.json';
if (file_exists($group_json_path)) {
    $raw = json_decode(file_get_contents($group_json_path), true);
    foreach ($raw ?? [] as $g) {
        if (!empty($g['email'])) {
            $known_groups[$g['email']] = $g['name'] ?? $g['email'];
        }
    }
}
$group_access = [];
try {
    $group_access = $db->query("SELECT * FROM musky_group_access")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $group_access = [];
}
if (empty($group_access)) {
    $group_access = musky_user_mysql_fetch_all_groups();
}
foreach ($group_access as $row) {
    if (!isset($known_groups[$row['group_email']])) {
        $known_groups[$row['group_email']] = $row['group_email'];
    }
}
$showHiddenGroups = (string)($_GET['show_hidden_groups'] ?? '') === '1';
$hiddenGroups = musky_admin_fetch_hidden_groups($db);
$hiddenGroupCount = count($hiddenGroups);

function safe($val) {
    return htmlspecialchars(is_null($val) || $val === '' ? 'N/A' : $val);
}

/**
 * Admin-facing user categorization for the tabbed view.
 *
 * IMPORTANT:
 * Musky currently stores org-unit data on the user row, but it does not keep
 * a durable per-user Google group membership table for everyone. Because of
 * that, "Techs and HDK" cannot yet be determined from the literal Google group
 * emails alone at render time. For now we use the user right you called out
 * as the operational definition: DEVICE_MANAGER.
 */
function musky_admin_user_bucket(array $user): string
{
    $orgUnit = strtolower(trim((string)($user['org_unit'] ?? '')));
    $allowed = array_filter(array_map('trim', explode(',', (string)($user['allowed_tools'] ?? ''))));

    if (in_array('DEVICE_MANAGER', $allowed, true)) {
        return 'techs';
    }

    if (str_starts_with($orgUnit, '/staff')) {
        return 'staff';
    }

    if (str_starts_with($orgUnit, '/students')) {
        return 'students';
    }

    return 'other';
}

$bucketedUsers = [
    'all' => $users,
    'techs' => [],
    'staff' => [],
    'students' => [],
    'other' => [],
];

foreach ($users as $userRow) {
    $bucket = musky_admin_user_bucket($userRow);
    $bucketedUsers[$bucket][] = $userRow;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Musky Admin Panel</title>
    <link rel="stylesheet" href="/theme.css?theme=<?= safe($theme) ?>">
    <style>
        body { padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
        th, td { border: 1px solid #ccc; padding: 10px; vertical-align: top; }
        .admin-buttons { margin-bottom: 20px; }
        .admin-buttons button { margin-right: 10px; }
        input[type="text"], input[type="password"] { width: 95%; }
        img { border-radius: 8px; }
        h3 { margin-top: 0; }
        .user-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0 18px 0;
        }
        .user-tab {
            border: 1px solid rgba(0,0,0,0.18);
            border-radius: 999px;
            padding: 9px 14px;
            background: rgba(255,255,255,0.70);
            cursor: pointer;
            font-weight: 700;
        }
        .user-tab.active {
            background: #1f6feb;
            color: #fff;
            border-color: #1f6feb;
        }
        .user-pane { display: none; }
        .user-pane.active { display: block; }
        .user-count {
            display: inline-block;
            margin-left: 6px;
            opacity: 0.85;
            font-size: 0.9em;
        }
        .user-pane-empty {
            padding: 16px 18px;
            margin-bottom: 18px;
            border: 1px dashed rgba(0,0,0,0.18);
            border-radius: 12px;
            background: rgba(255,255,255,0.45);
        }
        .extra-memberships {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .membership-tag {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            border: 1px solid rgba(0,0,0,0.20);
            background: rgba(31,111,235,0.10);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .jump-link-button {
            display: inline-block;
            margin-right: 10px;
            border: 1px solid rgba(0,0,0,0.22);
            border-radius: 8px;
            padding: 7px 11px;
            text-decoration: none;
            font-weight: 700;
            background: rgba(255,255,255,0.78);
            color: inherit;
        }
        .quick-filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 12px 0;
        }
        .quick-filter-btn {
            border: 1px solid rgba(0,0,0,0.22);
            border-radius: 999px;
            padding: 7px 12px;
            background: rgba(255,255,255,0.78);
            cursor: pointer;
            font-weight: 700;
        }
        .quick-filter-btn.active {
            background: #1f6feb;
            border-color: #1f6feb;
            color: #fff;
        }
        .group-access-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 28px;
        }
        .group-search-wrap {
            margin: 12px 0;
        }
        .group-column-toggles {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin: 0 0 10px 0;
            padding: 10px 12px;
            border: 1px dashed rgba(0,0,0,0.2);
            border-radius: 10px;
            background: rgba(255,255,255,0.45);
        }
        .group-row.hidden-row-marker {
            opacity: 0.78;
            background: rgba(255,165,0,0.08);
        }
    </style>
</head>
<body class="<?= safe($theme) ?>">

<div class="admin-buttons">
    <button onclick="location.href='/index.php'">🏠 Return to Main Page</button>
    <button onclick="location.reload()">🔄 Refresh</button>
    <a href="#group-access-section" class="jump-link-button">⬇ Jump to Group Access</a>
    <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="bulk_import">
        <input type="hidden" name="csrf_token" value="<?= safe($csrfToken) ?>">
        <button type="submit">📥 Import Legacy Users Now</button>
    </form>
</div>

<h1>🎓 Musky Admin Panel</h1>

<div style="margin-bottom:20px;padding:12px 14px;border:1px solid #ccc;border-radius:10px;background:rgba(0,0,0,0.04);">
    <strong>Backend Sync Status:</strong><br>
    Primary user store backend: <?= htmlspecialchars(strtoupper($dbBackend ?: 'unknown')) ?><br>
    This page now reads/writes the primary store first and keeps the other side warm when available.<br>
    Users synced this load: <?= (int)($syncSummary['users_synced'] ?? 0) ?> / <?= (int)($syncSummary['users_seen'] ?? 0) ?><br>
    Groups synced this load: <?= (int)($syncSummary['groups_synced'] ?? 0) ?> / <?= (int)($syncSummary['groups_seen'] ?? 0) ?><br>
    Legacy local users synced this load: <?= (int)($syncSummary['legacy_users_synced'] ?? 0) ?> / <?= (int)($syncSummary['legacy_users_seen'] ?? 0) ?><br>
    MariaDB mirror ready: <?= !empty($syncSummary['mysql_ready']) ? 'Yes' : 'No' ?><br>
    SQLite source path detected: <?= htmlspecialchars($sqliteSourcePath ?: 'Not found') ?>
</div>

<?php if (is_array($syncFlash)): ?>
<div style="margin-bottom:20px;padding:12px 14px;border:1px solid #4caf50;border-radius:10px;background:rgba(76,175,80,0.10);">
    <strong>Manual Import Complete:</strong><br>
    Users synced: <?= (int)($syncFlash['users_synced'] ?? 0) ?> / <?= (int)($syncFlash['users_seen'] ?? 0) ?><br>
    Groups synced: <?= (int)($syncFlash['groups_synced'] ?? 0) ?> / <?= (int)($syncFlash['groups_seen'] ?? 0) ?><br>
    Legacy local users synced: <?= (int)($syncFlash['legacy_users_synced'] ?? 0) ?> / <?= (int)($syncFlash['legacy_users_seen'] ?? 0) ?>
</div>
<?php endif; ?>

<h2>➕ Add OldSkool User</h2>
<form method="post" style="margin-bottom:30px;">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="csrf_token" value="<?= safe($csrfToken) ?>">
    Username: <input name="new_username" required>
    Password: <input name="new_password" type="password" required>
    Email: <input name="new_email">
    Theme: <input name="new_theme" placeholder="light-mode / dark-mode / musky-mode">
    <button type="submit">Create User</button>
</form>

<h2>🧑 Users</h2>
<div style="margin:0 0 14px 0;">
    <input
        type="text"
        id="userSearch"
        placeholder="Search users by name, email, org unit, building, or tools..."
        style="width:100%;max-width:720px;padding:10px 12px;border:1px solid rgba(0,0,0,0.2);border-radius:10px;"
    >
</div>
<div class="quick-filter-row">
    <button type="button" class="quick-filter-btn active" data-quick-filter="all">Show All Users</button>
    <button type="button" class="quick-filter-btn" data-quick-filter="faculty">Show All Staff (<?= safe(musky_admin_domain_label($staffDomainSuffixes)) ?>)</button>
    <button type="button" class="quick-filter-btn" data-quick-filter="students">Show All Students (<?= safe(musky_admin_domain_label($studentDomainSuffixes)) ?>)</button>
    <button type="button" class="quick-filter-btn" data-quick-filter="hdk">Show All HDK (<?= safe(musky_admin_domain_label($hdkDomainSuffixes)) ?> + DEVICE_MANAGER)</button>
</div>
<div style="margin:0 0 16px 0;padding:10px 12px;border:1px dashed rgba(0,0,0,0.2);border-radius:10px;background:rgba(255,255,255,0.45);font-size:0.95em;">
    <strong>Assignment policy notes:</strong><br>
    New HDK level codes are <em>individual-only</em> and do not appear in Group Access.<br>
    Top-tier controls are hidden in individual assignment for restricted domains (<?= safe(musky_admin_domain_label($restrictedDomainSuffixes)) ?>).
</div>
<div class="user-tabs" role="tablist" aria-label="User categories">
    <button type="button" class="user-tab active" data-user-tab="all">
        All<span class="user-count"><?= count($bucketedUsers['all']) ?></span>
    </button>
    <button type="button" class="user-tab" data-user-tab="techs">
        Techs and HDK<span class="user-count"><?= count($bucketedUsers['techs']) ?></span>
    </button>
    <button type="button" class="user-tab" data-user-tab="staff">
        Staff<span class="user-count"><?= count($bucketedUsers['staff']) ?></span>
    </button>
    <button type="button" class="user-tab" data-user-tab="students">
        Students<span class="user-count"><?= count($bucketedUsers['students']) ?></span>
    </button>
    <?php if (!empty($bucketedUsers['other'])): ?>
    <button type="button" class="user-tab" data-user-tab="other">
        Other<span class="user-count"><?= count($bucketedUsers['other']) ?></span>
    </button>
    <?php endif; ?>
</div>

<?php
$userTabLabels = [
    'all' => 'All Users',
    'techs' => 'Techs and HDK',
    'staff' => 'Staff',
    'students' => 'Students',
    'other' => 'Other / Unclassified',
];
foreach ($userTabLabels as $tabKey => $tabLabel):
    $paneUsers = $bucketedUsers[$tabKey] ?? [];
?>
<div class="user-pane <?= $tabKey === 'all' ? 'active' : '' ?>" data-user-pane="<?= safe($tabKey) ?>">
<?php if (empty($paneUsers)): ?>
    <div class="user-pane-empty">No users currently match <strong><?= safe($tabLabel) ?></strong>.</div>
<?php endif; ?>
<?php foreach ($paneUsers as $u): ?>
<form
    method="post"
    class="user-card-form"
    data-user-email="<?= safe(strtolower((string)($u['email'] ?? ''))) ?>"
    data-user-tools="<?= safe(strtoupper((string)($u['allowed_tools'] ?? ''))) ?>"
    data-user-search="<?= safe(implode(' ', [
    $u['email'] ?? '',
    $u['full_name'] ?? '',
    $u['first_name'] ?? '',
    $u['last_name'] ?? '',
    $u['org_unit'] ?? '',
    $u['building'] ?? '',
    $u['title'] ?? '',
    $u['department'] ?? '',
    $u['location'] ?? '',
    $u['allowed_tools'] ?? '',
])) ?>"
>
<input type="hidden" name="csrf_token" value="<?= safe($csrfToken) ?>">
<table>
<tr>
    <td rowspan="2" style="width:90px;">
        <?php $img = !empty($u['photo_url']) ? safe($u['photo_url']) : '/MuskyPaw.png'; ?>
        <img src="<?= $img ?>" width="64" height="64">
    </td>
    <td>
        <strong><?= safe($u['full_name'] ?: $u['first_name'] . ' ' . $u['last_name']) ?></strong>
        <?php $extraMemberships = musky_admin_extra_membership_codes_from_csv((string)($u['allowed_tools'] ?? '')); ?>
        <?php if (!empty($extraMemberships)): ?>
        <div class="extra-memberships">
            <?php foreach ($extraMemberships as $membershipCode): ?>
            <?php $membershipLabel = $tool_map[$membershipCode] ?? $membershipCode; ?>
            <span class="membership-tag" title="<?= safe($membershipLabel) ?>"><?= safe($membershipCode) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </td>
    <td>
        <button type="submit" name="action" value="delete" onclick="return confirm('Delete this user?')">❌ Delete</button>
        <button type="button" onclick="this.closest('form').querySelectorAll('input,select').forEach(i=>i.disabled=false);">✏️ Edit</button>
        <button type="submit" name="action" value="update">💾 Save</button>
        <input type="hidden" name="email" value="<?= safe($u['email']) ?>">
    </td>
</tr>
<tr>
    <td colspan="2">
        Email: <input name="email_display" value="<?= safe($u['email']) ?>" disabled><br>
        Theme: <input name="theme" value="<?= safe($u['theme'] ?? '') ?>" disabled><br>
        Role: <input name="role" value="<?= safe($u['role']) ?>" disabled>
        Building: <input name="building" value="<?= safe($u['building']) ?>" disabled><br>
        First: <input name="first_name" value="<?= safe($u['first_name']) ?>" disabled>
        Last: <input name="last_name" value="<?= safe($u['last_name']) ?>" disabled><br>
        Org Unit: <input name="org_unit" value="<?= safe($u['org_unit']) ?>" disabled>
        Title: <input name="title" value="<?= safe($u['title']) ?>" disabled><br>
        Dept: <input name="department" value="<?= safe($u['department']) ?>" disabled>
        Loc: <input name="location" value="<?= safe($u['location']) ?>" disabled>
        Suspended: <input type="checkbox" name="is_suspended" <?= !empty($u['is_suspended']) ? 'checked' : '' ?> disabled><br>
        Photo URL: <input name="photo_url" value="<?= safe($u['photo_url']) ?>" disabled><br><br>

        <strong>Tool Access:</strong><br>
        <?php
        // Per-user tool chooser is filtered by email-specific policy.
        // Example: top-tier controls are hidden for restricted domains.
        $individual_tool_map = musky_admin_individual_assignable_tool_map_for_email((string)($u['email'] ?? ''));
        ?>
        <?php foreach ($individual_tool_map as $tool => $label): ?>
            <label>
                <input type="checkbox" name="tools[]" value="<?= $tool ?>" <?= in_array($tool, explode(',', $u['allowed_tools'])) ? 'checked' : '' ?> disabled>
                <?= safe($label) ?>
            </label>
        <?php endforeach; ?>
    </td>
</tr>
</table>
</form>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div id="group-access-section" class="group-access-header">
    <h2 style="margin:0;">👥 Group Access</h2>
    <span style="font-size:0.95em;opacity:0.85;">Hidden groups: <?= (int)$hiddenGroupCount ?></span>
    <?php if ($showHiddenGroups): ?>
        <a href="musky_admin.php#group-access-section" class="jump-link-button" style="margin-right:0;">Hide Hidden Groups</a>
    <?php else: ?>
        <a href="musky_admin.php?show_hidden_groups=1#group-access-section" class="jump-link-button" style="margin-right:0;">Show Hidden Groups</a>
    <?php endif; ?>
</div>

<div class="group-search-wrap">
    <input
        type="text"
        id="groupSearch"
        placeholder="Search groups by email, name, or assigned tools..."
        style="width:100%;max-width:720px;padding:10px 12px;border:1px solid rgba(0,0,0,0.2);border-radius:10px;"
    >
</div>

<div class="group-column-toggles">
    <strong style="width:100%;">Column Visibility</strong>
    <label><input type="checkbox" class="group-col-toggle" data-col="email" checked> Group Email</label>
    <label><input type="checkbox" class="group-col-toggle" data-col="name" checked> Group Name</label>
    <label><input type="checkbox" class="group-col-toggle" data-col="tools" checked> Tools</label>
    <label><input type="checkbox" class="group-col-toggle" data-col="action" checked> Action</label>
</div>

<?php
// Build the render list with hide-state filtering.
//
// - Hidden groups are omitted by default.
// - When "Show Hidden Groups" is enabled, we still show them but mark rows.
$displayableGroups = [];
foreach ($known_groups as $group_email => $group_name) {
    $groupKey = strtolower(trim((string)$group_email));
    $isHidden = isset($hiddenGroups[$groupKey]);
    if ($isHidden && !$showHiddenGroups) {
        continue;
    }
    $displayableGroups[$group_email] = [
        'name' => $group_name,
        'is_hidden' => $isHidden,
    ];
}
?>

<?php if (!empty($displayableGroups)): ?>
<table id="groupAccessTable">
    <tr>
        <th class="group-col-email">Group Email</th>
        <th class="group-col-name">Group Name</th>
        <th class="group-col-tools">Tools</th>
        <th class="group-col-action">Action</th>
    </tr>
    <?php foreach ($displayableGroups as $group_email => $group_meta):
        $group_name = $group_meta['name'] ?? $group_email;
        $isHidden = !empty($group_meta['is_hidden']);
        $row = array_filter($group_access, fn($g) => $g['group_email'] === $group_email);
        $tools = !empty($row) ? explode(',', array_values($row)[0]['allowed_tools']) : [];
        $groupSearchText = strtolower(trim($group_email . ' ' . $group_name . ' ' . implode(',', $tools)));
    ?>
    <tr class="group-row <?= $isHidden ? 'hidden-row-marker' : '' ?>" data-group-search="<?= safe($groupSearchText) ?>">
        <form method="post">
            <input type="hidden" name="group_email" value="<?= safe($group_email) ?>">
            <input type="hidden" name="return_show_hidden_groups" value="<?= $showHiddenGroups ? '1' : '0' ?>">
            <input type="hidden" name="csrf_token" value="<?= safe($csrfToken) ?>">

            <td class="group-col-email"><?= safe($group_email) ?></td>
            <td class="group-col-name">
                <?= safe($group_name) ?>
                <?php if ($isHidden): ?>
                    <div style="font-size:0.84em;opacity:0.8;">Currently hidden from default view.</div>
                <?php endif; ?>
            </td>
            <td class="group-col-tools">
                <?php
                // Group tool chooser intentionally excludes non-group-assignable
                // codes (including all NEW (+) levels).
                ?>
                <?php foreach ($group_tool_map as $tool_code => $label): ?>
                    <label>
                        <input type="checkbox" name="group_tools[]" value="<?= $tool_code ?>" <?= in_array($tool_code, $tools) ? 'checked' : '' ?>>
                        <?= safe($label) ?>
                    </label>
                <?php endforeach; ?>
            </td>
            <td class="group-col-action">
                <button type="submit" name="action" value="update_group">💾 Save</button>
                <?php if ($isHidden): ?>
                    <button type="submit" name="action" value="unhide_group">👁️ Unhide</button>
                <?php else: ?>
                    <button type="submit" name="action" value="hide_group" onclick="return confirm('Hide this group from default list?')">🙈 Hide</button>
                <?php endif; ?>
            </td>
        </form>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
    <p>No groups match the current hidden/visible view.</p>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tabs = Array.from(document.querySelectorAll('.user-tab'));
    const panes = Array.from(document.querySelectorAll('.user-pane'));
    const searchInput = document.getElementById('userSearch');
    const quickFilterButtons = Array.from(document.querySelectorAll('.quick-filter-btn'));
    const groupSearchInput = document.getElementById('groupSearch');
    const groupRows = Array.from(document.querySelectorAll('.group-row'));
    const groupColumnToggles = Array.from(document.querySelectorAll('.group-col-toggle'));

    // Quick user-filter state:
    // - all
    // - faculty   (email domain filter)
    // - students  (email domain filter)
    // - hdk       (domain + DEVICE_MANAGER tool)
    let activeQuickFilter = 'all';

    const facultyDomains = <?= json_encode(array_values($staffDomainSuffixes), JSON_UNESCAPED_SLASHES) ?>;
    const studentDomains = <?= json_encode(array_values($studentDomainSuffixes), JSON_UNESCAPED_SLASHES) ?>;
    const hdkDomains = <?= json_encode(array_values($hdkDomainSuffixes), JSON_UNESCAPED_SLASHES) ?>;

    function activateUserTab(tabName) {
        tabs.forEach((tab) => {
            tab.classList.toggle('active', tab.dataset.userTab === tabName);
        });
        panes.forEach((pane) => {
            pane.classList.toggle('active', pane.dataset.userPane === tabName);
        });
        applyUserSearch();
    }

    function endsWithAnyDomain(email, domains) {
        return domains.some((domain) => email.endsWith(domain));
    }

    function hasToolCode(toolsCsv, toolCode) {
        const set = toolsCsv
            .split(',')
            .map((part) => part.trim().toUpperCase())
            .filter(Boolean);
        return set.includes(toolCode.toUpperCase());
    }

    function passesQuickFilter(form) {
        const email = (form.dataset.userEmail || '').toLowerCase();
        const toolsCsv = (form.dataset.userTools || '').toUpperCase();

        if (activeQuickFilter === 'faculty') {
            return endsWithAnyDomain(email, facultyDomains);
        }

        if (activeQuickFilter === 'students') {
            return endsWithAnyDomain(email, studentDomains);
        }

        if (activeQuickFilter === 'hdk') {
            return endsWithAnyDomain(email, hdkDomains) && hasToolCode(toolsCsv, 'DEVICE_MANAGER');
        }

        return true;
    }

    function setActiveQuickFilter(nextFilter) {
        activeQuickFilter = nextFilter || 'all';
        quickFilterButtons.forEach((button) => {
            button.classList.toggle('active', button.dataset.quickFilter === activeQuickFilter);
        });
        applyUserSearch();
    }

    function applyUserSearch() {
        const needle = (searchInput?.value || '').trim().toLowerCase();

        panes.forEach((pane) => {
            const forms = Array.from(pane.querySelectorAll('.user-card-form'));
            let visibleCount = 0;

            forms.forEach((form) => {
                const haystack = (form.dataset.userSearch || '').toLowerCase();
                const matchesSearch = needle === '' || haystack.includes(needle);
                const matchesQuickFilter = passesQuickFilter(form);
                const show = matchesSearch && matchesQuickFilter;
                form.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            let empty = pane.querySelector('.user-pane-empty.dynamic-search-empty');
            if (visibleCount === 0 && forms.length > 0 && needle !== '') {
                if (!empty) {
                    empty = document.createElement('div');
                    empty.className = 'user-pane-empty dynamic-search-empty';
                    empty.textContent = 'No users match the current search/filter in this tab.';
                    pane.insertBefore(empty, pane.firstChild);
                }
            } else if (empty) {
                empty.remove();
            }
        });
    }

    function applyGroupSearch() {
        const needle = (groupSearchInput?.value || '').trim().toLowerCase();
        groupRows.forEach((row) => {
            const haystack = (row.dataset.groupSearch || '').toLowerCase();
            const show = needle === '' || haystack.includes(needle);
            row.style.display = show ? '' : 'none';
        });
    }

    function applyGroupColumnVisibility() {
        groupColumnToggles.forEach((toggle) => {
            const col = toggle.dataset.col || '';
            const visible = !!toggle.checked;
            const cells = document.querySelectorAll('.group-col-' + col);
            cells.forEach((cell) => {
                cell.style.display = visible ? '' : 'none';
            });
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => activateUserTab(tab.dataset.userTab));
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyUserSearch);
    }

    quickFilterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            setActiveQuickFilter(button.dataset.quickFilter || 'all');
        });
    });

    if (groupSearchInput) {
        groupSearchInput.addEventListener('input', applyGroupSearch);
    }

    groupColumnToggles.forEach((toggle) => {
        toggle.addEventListener('change', applyGroupColumnVisibility);
    });

    // Initial UI state alignment.
    setActiveQuickFilter('all');
    applyGroupSearch();
    applyGroupColumnVisibility();
});
</script>

</body>
</html>
