<?php
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyUserMariaSync.php';

$prefs = musky_get_logged_in_user_prefs();

$email = $prefs['email'];
$firstName = $prefs['first_name'];
$theme = $prefs['theme'];

$mysqlUserRow = $email ? musky_user_mysql_fetch_user_by_email($email) : null;
if (is_array($mysqlUserRow) && !empty($mysqlUserRow['theme'])) {
    $theme = (string) $mysqlUserRow['theme'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>User Preferences - Musky</title>
  <link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>" />
  <style>
    :root {
      --pref-panel-bg: rgba(255,255,255,0.84);
      --pref-panel-border: rgba(0,0,0,0.10);
      --pref-panel-shadow: 0 20px 46px rgba(0,0,0,0.10);
      --pref-muted: rgba(0,0,0,0.68);
      --pref-accent: #2e7d32;
      --pref-heading-accent: #2e7d32;
      --pref-accent-soft: rgba(46, 125, 50, 0.12);
      --pref-card-bg: rgba(255,255,255,0.52);
    }
    body.dark-mode {
      --pref-panel-bg: rgba(22,22,22,0.92);
      --pref-panel-border: rgba(255,255,255,0.08);
      --pref-panel-shadow: 0 22px 52px rgba(0,0,0,0.32);
      --pref-muted: rgba(255,255,255,0.70);
      --pref-accent: #7ccf84;
      --pref-heading-accent: #bfe7c4;
      --pref-accent-soft: rgba(124, 207, 132, 0.14);
      --pref-card-bg: rgba(255,255,255,0.04);
    }
    body.musky-mode {
      --pref-panel-bg: rgba(0,40,85,0.82);
      --pref-panel-border: rgba(255,136,0,0.24);
      --pref-panel-shadow: 0 24px 54px rgba(0,18,38,0.34);
      --pref-muted: rgba(255,255,255,0.74);
      --pref-accent: #ff8800;
      --pref-heading-accent: #f4f7fb;
      --pref-accent-soft: rgba(255, 136, 0, 0.16);
      --pref-card-bg: rgba(255,255,255,0.04);
    }
    body.gator-time-mode {
      --pref-panel-bg: rgba(16,16,16,0.92);
      --pref-panel-border: rgba(197,179,88,0.22);
      --pref-panel-shadow: 0 24px 54px rgba(0,0,0,0.42);
      --pref-muted: rgba(255,255,255,0.70);
      --pref-accent: #C5B358;
      --pref-heading-accent: #efe5af;
      --pref-accent-soft: rgba(197, 179, 88, 0.16);
      --pref-card-bg: rgba(255,255,255,0.04);
    }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: sans-serif;
    }
    .pref-shell {
      width: min(1120px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 40px;
    }
    .pref-panel {
      background: var(--pref-panel-bg);
      border: 1px solid var(--pref-panel-border);
      border-radius: 28px;
      box-shadow: var(--pref-panel-shadow);
      backdrop-filter: blur(12px);
      overflow: hidden;
    }
    .pref-header {
      padding: 28px 30px 24px;
      border-bottom: 1px solid var(--pref-panel-border);
      background:
        radial-gradient(circle at top left, var(--pref-accent-soft), transparent 42%),
        linear-gradient(135deg, rgba(255,255,255,0.08), transparent 70%);
    }
    .pref-kicker {
      margin: 0 0 8px 0;
      color: var(--pref-heading-accent);
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 0.78rem;
      font-weight: 800;
    }
    .pref-header h1 {
      margin: 0;
      font-size: clamp(1.8rem, 3vw, 2.5rem);
      line-height: 1.05;
    }
    .pref-header p {
      margin: 12px 0 0 0;
      max-width: 64ch;
      color: var(--pref-muted);
      line-height: 1.5;
    }
    .pref-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 18px;
    }
    .pref-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 12px;
      font-weight: 700;
      text-decoration: none;
      border: 1px solid var(--pref-panel-border);
      color: inherit;
      background: rgba(255,255,255,0.18);
      transition: transform 0.18s ease;
    }
    .pref-link:hover {
      transform: translateY(-1px);
    }
    .pref-content {
      padding: 24px 30px 30px;
    }
    .pref-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 18px;
    }
    .pref-card {
      border: 1px solid var(--pref-panel-border);
      border-radius: 22px;
      padding: 20px;
      background: var(--pref-card-bg);
      box-shadow: 0 14px 28px rgba(0,0,0,0.08);
    }
    .pref-card-kicker {
      color: var(--pref-heading-accent);
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-size: 0.72rem;
      font-weight: 800;
      margin-bottom: 8px;
    }
    .pref-card h2 {
      margin: 0;
      font-size: 1.3rem;
    }
    .pref-card p {
      margin: 12px 0 0 0;
      color: var(--pref-muted);
      line-height: 1.45;
    }
    .pref-card-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      margin-top: 18px;
      padding: 0 16px;
      border-radius: 12px;
      background: var(--pref-accent);
      color: #fff;
      font-weight: 800;
      text-decoration: none;
    }
    body.gator-time-mode .pref-card-action {
      color: #161616;
    }
    .pref-preview {
      display: flex;
      gap: 8px;
      margin-top: 16px;
    }
    .pref-preview span {
      flex: 1;
      height: 14px;
      border-radius: 999px;
      background: rgba(255,255,255,0.24);
    }
    .pref-placeholder {
      border-style: dashed;
    }
    @media (max-width: 920px) {
      .pref-shell {
        width: min(100%, calc(100% - 20px));
        padding: 18px 0 26px;
      }
      .pref-header {
        padding: 22px 18px 18px;
      }
      .pref-content {
        padding: 18px;
      }
    }
  </style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
  <div class="pref-shell">
    <div class="pref-panel">
      <div class="pref-header">
        <p class="pref-kicker">Preferences</p>
        <h1>User Preferences<?= $firstName ? ', ' . htmlspecialchars($firstName) : '' ?>.</h1>
        <p>
          This is the landing area for personal Musky settings. Each pane can grow on its own without crowding the others.
          Start with themes, and we can add more preferences here as they come online.
        </p>
        <div class="pref-actions">
          <a class="pref-link" href="../index.php">Back to Hub</a>
        </div>
      </div>

      <div class="pref-content">
        <div class="pref-grid">
          <section class="pref-card">
            <div class="pref-card-kicker">Live Now</div>
            <h2>Theme &amp; Appearance</h2>
            <p>Choose the Musky look you want, then preview the cards, sidebar, and action colors before saving.</p>
            <div class="pref-preview">
              <span></span>
              <span style="opacity: 0.82;"></span>
              <span style="opacity: 0.68;"></span>
            </div>
            <a class="pref-card-action" href="User_SetThemes.php">Open Theme Settings</a>
          </section>

          <section class="pref-card">
            <div class="pref-card-kicker">Live Now</div>
            <h2>Security</h2>
            <p>Review your Musky login and logout history so you can always confirm your account access activity.</p>
            <div class="pref-preview">
              <span></span>
              <span style="opacity: 0.82;"></span>
              <span style="opacity: 0.68;"></span>
            </div>
            <a class="pref-card-action" href="User_Security.php">Open Security</a>
          </section>

          <section class="pref-card pref-placeholder">
            <div class="pref-card-kicker">Coming Soon</div>
            <h2>More Preference Panes</h2>
            <p>This landing page is ready for additional user settings later, like dashboard defaults, work preferences, or notification controls.</p>
          </section>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
