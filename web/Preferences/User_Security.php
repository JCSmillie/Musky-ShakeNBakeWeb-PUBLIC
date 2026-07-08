<?php
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/MuskyUserMariaSync.php';

$prefs = musky_get_logged_in_user_prefs();

$email = trim((string)($prefs['email'] ?? ''));
$firstName = $prefs['first_name'] ?? '';
$theme = $prefs['theme'] ?? 'musky-mode';

$mysqlUserRow = $email ? musky_user_mysql_fetch_user_by_email($email) : null;
if (is_array($mysqlUserRow) && !empty($mysqlUserRow['theme'])) {
    $theme = (string) $mysqlUserRow['theme'];
}

$authEvents = $email !== '' ? musky_activity_get_user_auth_events($email, 500) : [];
$loginCount = 0;
$logoutCount = 0;
$lastLogin = null;
$lastLogout = null;

foreach ($authEvents as $event) {
    $type = strtoupper((string)($event['event_type'] ?? ''));
    if ($type === 'LOGIN') {
        $loginCount++;
        if ($lastLogin === null) {
            $lastLogin = (string)($event['event_time'] ?? '');
        }
    } elseif ($type === 'LOGOUT') {
        $logoutCount++;
        if ($lastLogout === null) {
            $lastLogout = (string)($event['event_time'] ?? '');
        }
    }
}

function musky_pref_format_activity_time(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not recorded';
    }

    try {
        return (new DateTime($value))->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $value;
    }
}

function musky_pref_auth_event_label(array $event): string
{
    $type = strtoupper((string)($event['event_type'] ?? ''));
    $action = trim((string)($event['action_name'] ?? ''));

    if ($type === 'LOGIN' && $action !== '') {
        $normalized = str_replace('_', ' ', strtolower($action));
        return ucwords($normalized);
    }

    if ($type === 'LOGOUT') {
        return 'Logout';
    }

    return $type !== '' ? $type : 'Activity';
}

function musky_pref_auth_detail(array $event): string
{
    $extra = trim((string)($event['extra_json'] ?? ''));
    if ($extra !== '') {
        $decoded = json_decode($extra, true);
        if (is_array($decoded) && !empty($decoded['provider'])) {
            return 'Provider: ' . strtoupper((string)$decoded['provider']);
        }
    }

    $page = trim((string)($event['page_path'] ?? ''));
    return $page !== '' ? $page : 'Musky auth event';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>User Preferences - Security - Musky</title>
  <link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>" />
  <style>
    :root {
      --security-panel-bg: rgba(255,255,255,0.84);
      --security-panel-border: rgba(0,0,0,0.10);
      --security-panel-shadow: 0 20px 46px rgba(0,0,0,0.10);
      --security-muted: rgba(0,0,0,0.68);
      --security-accent: #2e7d32;
      --security-heading-accent: #2e7d32;
      --security-accent-soft: rgba(46, 125, 50, 0.12);
      --security-card-bg: rgba(255,255,255,0.40);
      --security-row-bg: rgba(255,255,255,0.22);
      --security-pill-login-bg: #dff6dd;
      --security-pill-login-fg: #176b1a;
      --security-pill-logout-bg: #fff0d9;
      --security-pill-logout-fg: #9a5c00;
    }
    body.dark-mode {
      --security-panel-bg: rgba(22,22,22,0.92);
      --security-panel-border: rgba(255,255,255,0.08);
      --security-panel-shadow: 0 22px 52px rgba(0,0,0,0.32);
      --security-muted: rgba(255,255,255,0.70);
      --security-accent: #7ccf84;
      --security-heading-accent: #bfe7c4;
      --security-accent-soft: rgba(124, 207, 132, 0.14);
      --security-card-bg: rgba(255,255,255,0.03);
      --security-row-bg: rgba(255,255,255,0.04);
    }
    body.musky-mode {
      --security-panel-bg: rgba(0,40,85,0.82);
      --security-panel-border: rgba(255,136,0,0.24);
      --security-panel-shadow: 0 24px 54px rgba(0,18,38,0.34);
      --security-muted: rgba(255,255,255,0.74);
      --security-accent: #ff8800;
      --security-heading-accent: #f4f7fb;
      --security-accent-soft: rgba(255, 136, 0, 0.16);
      --security-card-bg: rgba(255,255,255,0.03);
      --security-row-bg: rgba(255,255,255,0.04);
    }
    body.gator-time-mode {
      --security-panel-bg: rgba(16,16,16,0.92);
      --security-panel-border: rgba(197,179,88,0.22);
      --security-panel-shadow: 0 24px 54px rgba(0,0,0,0.42);
      --security-muted: rgba(255,255,255,0.70);
      --security-accent: #C5B358;
      --security-heading-accent: #efe5af;
      --security-accent-soft: rgba(197, 179, 88, 0.16);
      --security-card-bg: rgba(255,255,255,0.03);
      --security-row-bg: rgba(255,255,255,0.04);
    }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: sans-serif;
    }
    .security-shell {
      width: min(1160px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 40px;
    }
    .security-panel {
      background: var(--security-panel-bg);
      border: 1px solid var(--security-panel-border);
      border-radius: 28px;
      box-shadow: var(--security-panel-shadow);
      backdrop-filter: blur(12px);
      overflow: hidden;
    }
    .security-header {
      padding: 28px 30px 22px;
      border-bottom: 1px solid var(--security-panel-border);
      background:
        radial-gradient(circle at top left, var(--security-accent-soft), transparent 40%),
        linear-gradient(135deg, rgba(255,255,255,0.08), transparent 70%);
    }
    .security-crumbs {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 14px;
      font-size: 0.88rem;
      color: var(--security-muted);
    }
    .security-crumbs a {
      color: inherit;
      font-weight: 700;
      text-decoration: none;
    }
    .security-crumbs a:hover {
      text-decoration: underline;
    }
    .security-kicker {
      margin: 0 0 8px 0;
      color: var(--security-heading-accent);
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 0.78rem;
      font-weight: 800;
    }
    .security-header h1 {
      margin: 0;
      font-size: clamp(1.8rem, 3vw, 2.5rem);
      line-height: 1.05;
    }
    .security-header p {
      margin: 12px 0 0 0;
      max-width: 68ch;
      color: var(--security-muted);
      line-height: 1.5;
    }
    .security-content {
      padding: 24px 30px 30px;
      display: grid;
      gap: 18px;
    }
    .security-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
    }
    .security-card {
      background: var(--security-card-bg);
      border: 1px solid var(--security-panel-border);
      border-radius: 22px;
      padding: 18px;
      box-sizing: border-box;
    }
    .security-card h2,
    .security-card h3 {
      margin: 0;
    }
    .security-card p {
      margin: 10px 0 0 0;
      color: var(--security-muted);
      line-height: 1.45;
    }
    .security-stat-value {
      font-size: 1.6rem;
      font-weight: 800;
      margin-top: 8px;
    }
    .security-stat-label {
      margin-top: 4px;
      color: var(--security-muted);
      font-size: 0.9rem;
    }
    .security-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 18px;
    }
    .security-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 12px;
      font-weight: 700;
      text-decoration: none;
      border: 1px solid var(--security-panel-border);
      color: inherit;
      background: rgba(255,255,255,0.18);
      transition: transform 0.18s ease;
    }
    .security-link:hover {
      transform: translateY(-1px);
    }
    .security-list {
      display: grid;
      gap: 12px;
      margin-top: 18px;
    }
    .security-row {
      display: grid;
      grid-template-columns: minmax(180px, 220px) minmax(120px, 150px) minmax(0, 1fr) minmax(140px, 180px);
      gap: 12px;
      align-items: start;
      padding: 14px 16px;
      border-radius: 18px;
      background: var(--security-row-bg);
      border: 1px solid var(--security-panel-border);
    }
    .security-row .stamp {
      font-weight: 800;
    }
    .security-row .detail,
    .security-row .meta {
      color: var(--security-muted);
      line-height: 1.4;
      overflow-wrap: anywhere;
    }
    .security-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 800;
      letter-spacing: 0.03em;
      width: fit-content;
    }
    .security-pill.login {
      background: var(--security-pill-login-bg);
      color: var(--security-pill-login-fg);
    }
    .security-pill.logout {
      background: var(--security-pill-logout-bg);
      color: var(--security-pill-logout-fg);
    }
    .security-empty {
      padding: 18px;
      border-radius: 18px;
      background: var(--security-row-bg);
      border: 1px solid var(--security-panel-border);
      color: var(--security-muted);
    }
    .security-note {
      font-size: 0.9rem;
      color: var(--security-muted);
    }
    @media (max-width: 960px) {
      .security-shell {
        width: min(100%, calc(100% - 20px));
        padding: 18px 0 26px;
      }
      .security-header {
        padding: 22px 18px 18px;
      }
      .security-content {
        padding: 18px;
      }
      .security-row {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
  <div class="security-shell">
    <div class="security-panel">
      <div class="security-header">
        <div class="security-crumbs">
          <a href="index.php">Preferences</a>
          <span>/</span>
          <span>Security</span>
        </div>
        <p class="security-kicker">Preferences</p>
        <h1>Security &amp; Login History<?= $firstName ? ', ' . htmlspecialchars($firstName) : '' ?>.</h1>
        <p>
          Review your Musky sign-in history here. This pane shows your login and logout events from the Musky activity log so you can always confirm when your account was used.
        </p>
      </div>

      <div class="security-content">
        <section class="security-stats">
          <div class="security-card">
            <h3>Login Events</h3>
            <div class="security-stat-value"><?= number_format($loginCount) ?></div>
            <div class="security-stat-label">Recorded sign-ins for this account</div>
          </div>
          <div class="security-card">
            <h3>Logout Events</h3>
            <div class="security-stat-value"><?= number_format($logoutCount) ?></div>
            <div class="security-stat-label">Recorded sign-outs for this account</div>
          </div>
          <div class="security-card">
            <h3>Last Login</h3>
            <div class="security-stat-value" style="font-size:1.15rem; line-height:1.3;"><?= htmlspecialchars(musky_pref_format_activity_time($lastLogin)) ?></div>
            <div class="security-stat-label">Most recent successful login event</div>
          </div>
          <div class="security-card">
            <h3>Last Logout</h3>
            <div class="security-stat-value" style="font-size:1.15rem; line-height:1.3;"><?= htmlspecialchars(musky_pref_format_activity_time($lastLogout)) ?></div>
            <div class="security-stat-label">Most recent recorded logout event</div>
          </div>
        </section>

        <section class="security-card">
          <h2>My Security Events</h2>
          <p>Only your own `LOGIN` and `LOGOUT` activity appears here. This keeps the pane simple and focused on account access history.</p>

          <div class="security-list">
            <?php if ($authEvents): ?>
              <?php foreach ($authEvents as $event): ?>
                <?php $type = strtoupper((string)($event['event_type'] ?? '')); ?>
                <div class="security-row">
                  <div class="stamp"><?= htmlspecialchars(musky_pref_format_activity_time((string)($event['event_time'] ?? ''))) ?></div>
                  <div>
                    <span class="security-pill <?= $type === 'LOGOUT' ? 'logout' : 'login' ?>">
                      <?= htmlspecialchars($type !== '' ? $type : 'EVENT') ?>
                    </span>
                  </div>
                  <div class="detail">
                    <strong><?= htmlspecialchars(musky_pref_auth_event_label($event)) ?></strong><br>
                    <?= htmlspecialchars(musky_pref_auth_detail($event)) ?>
                  </div>
                  <div class="meta">
                    <?php if (!empty($event['ip_address'])): ?>
                      IP: <?= htmlspecialchars((string)$event['ip_address']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($event['request_method'])): ?>
                      Method: <?= htmlspecialchars((string)$event['request_method']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($event['session_id'])): ?>
                      Session: <?= htmlspecialchars((string)$event['session_id']) ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="security-empty">
                No login or logout activity has been recorded for this account yet.
              </div>
            <?php endif; ?>
          </div>

          <div class="security-actions">
            <a class="security-link" href="index.php">Back to Preferences</a>
            <a class="security-link" href="../index.php">Back to Hub</a>
          </div>
        </section>

        <div class="security-note">
          This pane reads from the same `musky_activity_log` source used by the admin activity dashboard, but filtered down to your own account access events.
        </div>
      </div>
    </div>
  </div>
</body>
</html>
