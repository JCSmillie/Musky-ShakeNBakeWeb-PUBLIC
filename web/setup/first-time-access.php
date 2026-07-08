<?php
session_start();

require_once __DIR__ . '/../../Functions/MuskyBootstrap.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/MuskyFirstTimeSetup.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';

function musky_first_time_safe_return_path(?string $candidate, string $default = '/index.php'): string
{
    $candidate = trim((string)$candidate);
    if ($candidate === '' || $candidate[0] !== '/' || preg_match('#^//#', $candidate)) {
        return $default;
    }

    return $candidate;
}

$returnTarget = musky_first_time_safe_return_path($_GET['return'] ?? '/index.php');
$csrfToken = musky_csrf_token('musky_first_time_access');
$status = musky_first_time_status();
$errors = [];
$success = $_SESSION['musky_first_time_success'] ?? null;
unset($_SESSION['musky_first_time_success']);

if (!$status['first_time_required'] && !$success) {
    $target = !empty($_SESSION['musky_user']['email']) ? '/index.php' : ('/auth/login.php?return=' . urlencode($returnTarget));
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!musky_csrf_is_valid('musky_first_time_access', (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your setup form expired. Please reload the page and try again.';
    } else {
        $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
        $password = (string)($_POST['admin_password'] ?? '');
        $confirm = (string)($_POST['admin_password_confirm'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a real admin email address.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Use a password that is at least 10 characters long.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'The password confirmation did not match.';
        }

        if (!$errors) {
            $result = musky_first_time_bootstrap($email, $password);
            if (!empty($result['ok'])) {
                $adminUser = is_array($result['admin']['musky_user'] ?? null) ? $result['admin']['musky_user'] : [];
                musky_first_time_log_user_in($adminUser, $email);
                musky_activity_log_login('local', [
                    'login_type' => 'local',
                    'redirect' => '/setup/first-time-access.php?done=1',
                    'first_time_access' => true,
                ]);

                $_SESSION['musky_first_time_success'] = [
                    'email' => $email,
                    'sample_asset_tag' => $result['sample_asset_tag'] ?? null,
                    'seed' => $result['seed'] ?? [],
                ];
                header('Location: /setup/first-time-access.php?done=1');
                exit;
            }

            $errors[] = trim((string)($result['error'] ?? 'The first-time bootstrap did not complete.'));
            $status = musky_first_time_status();
        }
    }
}

$sampleAssetTag = is_array($success) ? ($success['sample_asset_tag'] ?? null) : ($status['sample_asset_tag'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musky First Time Access</title>
    <link rel="stylesheet" href="/theme.css?theme=musky-mode">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 184, 77, 0.22), transparent 34%),
                radial-gradient(circle at bottom right, rgba(75, 157, 255, 0.18), transparent 38%),
                linear-gradient(160deg, #ecf7eb 0%, #d7efe6 46%, #c7e6f7 100%);
            color: #14314b;
        }

        .shell {
            width: min(980px, calc(100% - 32px));
            margin: 32px auto;
            display: grid;
            gap: 18px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(20, 49, 75, 0.10);
            border-radius: 18px;
            box-shadow: 0 18px 44px rgba(20, 49, 75, 0.12);
            padding: 24px;
        }

        h1, h2 {
            margin-top: 0;
        }

        .hero {
            display: grid;
            gap: 10px;
        }

        .hero p,
        .panel p {
            margin: 0;
            line-height: 1.55;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .status-card {
            background: rgba(20, 49, 75, 0.05);
            border-radius: 14px;
            padding: 14px 16px;
        }

        .status-label {
            display: block;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #47647e;
            margin-bottom: 6px;
        }

        .status-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #14314b;
        }

        .good {
            color: #17744d;
        }

        .warn {
            color: #b06300;
        }

        .bad {
            color: #af2d2d;
        }

        .error-list,
        .success-box {
            border-radius: 14px;
            padding: 16px 18px;
        }

        .error-list {
            background: rgba(175, 45, 45, 0.10);
            border: 1px solid rgba(175, 45, 45, 0.18);
            color: #7c1f1f;
        }

        .success-box {
            background: rgba(23, 116, 77, 0.10);
            border: 1px solid rgba(23, 116, 77, 0.18);
            color: #145a3d;
        }

        .error-list ul {
            margin: 10px 0 0;
            padding-left: 18px;
        }

        form {
            display: grid;
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 6px;
        }

        label {
            font-weight: 700;
        }

        input {
            border: 1px solid rgba(20, 49, 75, 0.18);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 1rem;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }

        .button,
        button[type="submit"] {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font-size: 0.98rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            background: #0f5f93;
            color: #fff;
        }

        .button.secondary {
            background: rgba(20, 49, 75, 0.10);
            color: #14314b;
        }

        code {
            background: rgba(20, 49, 75, 0.08);
            border-radius: 6px;
            padding: 2px 6px;
        }
    </style>
</head>
<body>
    <div class="shell">
        <section class="panel hero">
            <h1>Musky First Time Access</h1>
            <p>This page is the clean bootstrap path for a brand-new Musky Docker install. It creates a local admin in Nora MariaDB, seeds the base Musky config rows, and gets you to a point where you can sign in and start testing DeviceManager without setting up Google SSO first.</p>
            <p>Google SSO, proxying, and broader access policy can come later. The goal here is to get a safe local admin and a working first search.</p>
        </section>

        <section class="panel">
            <h2>Readiness Check</h2>
            <div class="status-grid">
                <div class="status-card">
                    <span class="status-label">MariaDB</span>
                    <span class="status-value <?= $status['db_ready'] ? 'good' : 'bad' ?>">
                        <?= $status['db_ready'] ? 'Connected' : 'Unavailable' ?>
                    </span>
                </div>
                <div class="status-card">
                    <span class="status-label">Config Store</span>
                    <span class="status-value <?= $status['config_store_ready'] ? 'good' : 'warn' ?>">
                        <?= $status['config_store_ready'] ? 'Present' : 'Will be created' ?>
                    </span>
                </div>
                <div class="status-card">
                    <span class="status-label">Local Users</span>
                    <span class="status-value"><?= (int)$status['local_login_count'] ?></span>
                </div>
                <div class="status-card">
                    <span class="status-label">Musky Users</span>
                    <span class="status-value"><?= (int)$status['musky_user_count'] ?></span>
                </div>
                <div class="status-card">
                    <span class="status-label">Admin Ready</span>
                    <span class="status-value <?= $status['admin_exists'] ? 'good' : 'warn' ?>">
                        <?= $status['admin_exists'] ? 'Yes' : 'Not yet' ?>
                    </span>
                </div>
                <div class="status-card">
                    <span class="status-label">Sample Lookup</span>
                    <span class="status-value <?= $status['sample_asset_tag'] ? 'good' : 'warn' ?>">
                        <?= $status['sample_asset_tag'] ? htmlspecialchars((string)$status['sample_asset_tag']) : 'Waiting for Nora data' ?>
                    </span>
                </div>
            </div>
        </section>

        <?php if ($errors): ?>
            <section class="panel error-list">
                <strong>Setup could not finish yet.</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (is_array($success)): ?>
            <section class="panel success-box">
                <h2>Musky is ready</h2>
                <p>Local admin <code><?= htmlspecialchars((string)($success['email'] ?? '')) ?></code> was created and you are signed in now.</p>
                <p>Base Musky config rows were seeded, TagDecode defaults were loaded, and the login gate is open for the new admin.</p>
                <div class="button-row">
                    <a class="button" href="/index.php">Open Musky Hub</a>
                    <a class="button secondary" href="/admin/musky_admin.php">Open Admin Panel</a>
                    <?php if ($sampleAssetTag): ?>
                        <a class="button secondary" href="/DeviceManager/index.php?assettag=<?= urlencode((string)$sampleAssetTag) ?>">Test DeviceManager with <?= htmlspecialchars((string)$sampleAssetTag) ?></a>
                    <?php endif; ?>
                </div>
                <?php if (!$sampleAssetTag): ?>
                    <p style="margin-top:14px;">No sample Nora lookup value was found yet. If you used Nora demo mode, give the first import a moment to finish, or run a live Nora import before testing DeviceManager.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="panel">
                <h2>Create the Local Admin</h2>
                <p>Use the admin email as the local login username. That keeps Musky’s legacy local login path and the MariaDB-backed <code>musky_users</code> row aligned.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="field">
                        <label for="admin_email">Admin Email / Local Username</label>
                        <input id="admin_email" name="admin_email" type="email" autocomplete="username" required placeholder="admin@example.org" value="<?= htmlspecialchars((string)($_POST['admin_email'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="admin_password">Admin Password</label>
                        <input id="admin_password" name="admin_password" type="password" autocomplete="new-password" required placeholder="At least 10 characters">
                    </div>
                    <div class="field">
                        <label for="admin_password_confirm">Confirm Password</label>
                        <input id="admin_password_confirm" name="admin_password_confirm" type="password" autocomplete="new-password" required placeholder="Repeat the password">
                    </div>
                    <button type="submit">Create Local Admin and Finish Bootstrap</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>What this page handles</h2>
            <p>It provisions the Musky login tables in Nora MariaDB, seeds the shared config-store rows Musky expects on a clean install, imports the default tag decode map, and creates a local admin account with broad access so you can finish the rest of the setup from inside Musky.</p>
            <p>It does not configure Google SSO, reverse proxying, or district-specific policy. Those stay as follow-up tasks after the first login.</p>
        </section>
    </div>
</body>
</html>
