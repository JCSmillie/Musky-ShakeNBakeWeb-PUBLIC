<?php
session_start();
require_once __DIR__ . '/../check_access.php';
require_once '../SSO/Google/config.php';

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

// Load theme from musky_users
$theme = 'light-mode';
$email = $_SESSION['musky_user']['email'] ?? '';
if (!empty($email)) {
    $stmt = $db->prepare("SELECT theme FROM musky_users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['theme'])) {
        $theme = $row['theme'];
    }
}

// Tool map
$tool_map = require __DIR__ . '/tool_codes.php';
$all_tools = array_keys($tool_map);

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $uname = trim($_POST['new_username']);
    $pword = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    $email = trim($_POST['new_email']);
    $theme = trim($_POST['new_theme']) ?: 'light-mode';

    if ($uname && $_POST['new_password']) {
        $db->prepare("INSERT INTO users (username, password, email, theme) VALUES (?, ?, ?, ?)")
           ->execute([$uname, $pword, $email, $theme]);
        $db->prepare("INSERT INTO users_2fa (username, email, theme) VALUES (?, ?, ?)")
           ->execute([$uname, $email, $theme]);
    }
    header("Location: musky_admin.php");
    exit;
}

// Handle user update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && isset($_POST['action'])) {
    $email = $_POST['email'];

    if ($_POST['action'] === 'delete') {
        $db->prepare("DELETE FROM musky_users WHERE email = ?")->execute([$email]);
        header("Location: musky_admin.php");
        exit;
    }

    $tools = implode(',', $_POST['tools'] ?? []);
    $db->prepare("
        UPDATE musky_users SET
            role = ?, building = ?, allowed_tools = ?, 
            full_name = ?, first_name = ?, last_name = ?, org_unit = ?, title = ?, 
            department = ?, location = ?, is_suspended = ?, photo_url = ?, theme = ?
        WHERE email = ?
    ")->execute([
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
    ]);
    header("Location: musky_admin.php");
    exit;
}

// Handle group update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_group') {
    $group_email = $_POST['group_email'];
    $group_tools = implode(',', $_POST['group_tools'] ?? []);
    $upsert = $db->prepare("REPLACE INTO musky_group_access (group_email, allowed_tools) VALUES (?, ?)");
    $upsert->execute([$group_email, $group_tools]);
    header("Location: musky_admin.php");
    exit;
}

// Load users
$users = $db->query("SELECT * FROM musky_users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);

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
$group_access = $db->query("SELECT * FROM musky_group_access")->fetchAll(PDO::FETCH_ASSOC);
foreach ($group_access as $row) {
    if (!isset($known_groups[$row['group_email']])) {
        $known_groups[$row['group_email']] = $row['group_email'];
    }
}

function safe($val) {
    return htmlspecialchars(is_null($val) || $val === '' ? 'N/A' : $val);
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
    </style>
</head>
<body class="<?= safe($theme) ?>">

<div class="admin-buttons">
    <button onclick="location.href='/index.php'">🏠 Return to Main Page</button>
    <button onclick="location.reload()">🔄 Refresh</button>
</div>

<h1>🎓 Musky Admin Panel</h1>

<h2>➕ Add OldSkool User</h2>
<form method="post" style="margin-bottom:30px;">
    <input type="hidden" name="action" value="create">
    Username: <input name="new_username" required>
    Password: <input name="new_password" type="password" required>
    Email: <input name="new_email">
    Theme: <input name="new_theme" placeholder="light-mode / dark-mode / musky-mode">
    <button type="submit">Create User</button>
</form>

<h2>🧑 Users</h2>
<?php foreach ($users as $u): ?>
<form method="post">
<table>
<tr>
    <td rowspan="2" style="width:90px;">
        <?php $img = !empty($u['photo_url']) ? safe($u['photo_url']) : '/MuskyPaw.png'; ?>
        <img src="<?= $img ?>" width="64" height="64">
    </td>
    <td><strong><?= safe($u['full_name'] ?: $u['first_name'] . ' ' . $u['last_name']) ?></strong></td>
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
        <?php foreach ($tool_map as $tool => $label): ?>
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

<h2>👥 Group Access</h2>
<?php if (!empty($known_groups)): ?>
<table>
    <tr><th>Group Email</th><th>Group Name</th><th>Tools</th><th>Action</th></tr>
    <?php foreach ($known_groups as $group_email => $group_name): 
        $row = array_filter($group_access, fn($g) => $g['group_email'] === $group_email);
        $tools = !empty($row) ? explode(',', array_values($row)[0]['allowed_tools']) : [];
    ?>
    <tr>
        <form method="post">
            <input type="hidden" name="action" value="update_group">
            <input type="hidden" name="group_email" value="<?= safe($group_email) ?>">
            <td><?= safe($group_email) ?></td>
            <td><?= safe($group_name) ?></td>
            <td>
                <?php foreach ($tool_map as $tool_code => $label): ?>
                    <label>
                        <input type="checkbox" name="group_tools[]" value="<?= $tool_code ?>" <?= in_array($tool_code, $tools) ? 'checked' : '' ?>>
                        <?= safe($label) ?>
                    </label>
                <?php endforeach; ?>
            </td>
            <td><button type="submit">💾 Save</button></td>
        </form>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
    <p>No group data found yet.</p>
<?php endif; ?>

</body>
</html>