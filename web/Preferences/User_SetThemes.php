<?php
session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/MuskyUserMariaSync.php';

$prefs = musky_get_logged_in_user_prefs();

$email = $prefs['email'];
$firstName = $prefs['first_name'];
$theme = $prefs['theme'];

$mysqlUserRow = $email ? musky_user_mysql_fetch_user_by_email($email) : null;
if (is_array($mysqlUserRow) && !empty($mysqlUserRow['theme'])) {
    $theme = (string) $mysqlUserRow['theme'];
}

$themeCsrfToken = musky_csrf_token('preferences_theme');

$themeOptions = [
    'light-mode' => [
        'label' => 'Light',
        'note' => 'Bright and clean for everyday work.',
    ],
    'dark-mode' => [
        'label' => 'Dark',
        'note' => 'Low-glare focus for long sessions.',
    ],
    'musky-mode' => [
        'label' => 'Musky Mode',
        'note' => 'Gateway blue and orange Musky styling.',
    ],
    'gator-time-mode' => [
        'label' => 'Gator Time',
        'note' => 'Gateway black and athletic gold styling.',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    if (!musky_csrf_is_valid('preferences_theme', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
        exit;
    }

    $newTheme = (string) $_POST['theme'];
    if (!array_key_exists($newTheme, $themeOptions)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'invalid_theme']);
        exit;
    }

    header('Content-Type: application/json');
    try {
        $pdo = musky_user_mysql_sync_pdo();
        if (!$pdo instanceof PDO) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'mysql_unavailable']);
            exit;
        }

        musky_user_mysql_sync_ensure_schema($pdo);

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO musky_users (
                email, theme, updated_at, created_at, source_db, last_synced_at
            ) VALUES (
                :email, :theme, :updated_at, COALESCE(:created_at, NOW()), 'preferences', :last_synced_at
            )
            ON DUPLICATE KEY UPDATE
                theme = VALUES(theme),
                updated_at = VALUES(updated_at),
                last_synced_at = VALUES(last_synced_at)
        ");
        $stmt->execute([
            'email' => $email,
            'theme' => $newTheme,
            'updated_at' => $now,
            'created_at' => $now,
            'last_synced_at' => $now,
        ]);

        if (!isset($_SESSION['musky_user']) || !is_array($_SESSION['musky_user'])) {
            $_SESSION['musky_user'] = [];
        }
        $_SESSION['musky_user']['theme'] = $newTheme;
        $GLOBALS['_musky_user_pref_cache'] = null;

        echo json_encode(['success' => true, 'theme' => $newTheme]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'mysql_theme_save_failed']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>User Preferences - Theme Settings - Musky</title>
  <link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>" />
  <style>
    :root {
      --settings-panel-bg: rgba(255,255,255,0.84);
      --settings-panel-border: rgba(0,0,0,0.10);
      --settings-panel-shadow: 0 20px 46px rgba(0,0,0,0.10);
      --settings-muted: rgba(0,0,0,0.68);
      --settings-accent: #2e7d32;
      --settings-heading-accent: #2e7d32;
      --settings-accent-soft: rgba(46, 125, 50, 0.12);
      --settings-preview-bg: rgba(245,247,245,0.94);
      --settings-preview-sidebar: rgba(46, 125, 50, 0.12);
      --settings-preview-card: rgba(255,255,255,0.96);
      --settings-preview-button: #2e7d32;
      --settings-preview-button-text: #ffffff;
    }
    body.dark-mode {
      --settings-panel-bg: rgba(22,22,22,0.92);
      --settings-panel-border: rgba(255,255,255,0.08);
      --settings-panel-shadow: 0 22px 52px rgba(0,0,0,0.32);
      --settings-muted: rgba(255,255,255,0.70);
      --settings-accent: #7ccf84;
      --settings-heading-accent: #bfe7c4;
      --settings-accent-soft: rgba(124, 207, 132, 0.14);
      --settings-preview-bg: rgba(13,16,19,0.98);
      --settings-preview-sidebar: rgba(124, 207, 132, 0.12);
      --settings-preview-card: rgba(30,34,38,0.98);
      --settings-preview-button: #7ccf84;
      --settings-preview-button-text: #0d1013;
    }
    body.musky-mode {
      --settings-panel-bg: rgba(0,40,85,0.82);
      --settings-panel-border: rgba(255,136,0,0.24);
      --settings-panel-shadow: 0 24px 54px rgba(0,18,38,0.34);
      --settings-muted: rgba(255,255,255,0.74);
      --settings-accent: #ff8800;
      --settings-heading-accent: #f4f7fb;
      --settings-accent-soft: rgba(255, 136, 0, 0.16);
      --settings-preview-bg: rgba(0,27,58,0.98);
      --settings-preview-sidebar: rgba(255,136,0,0.16);
      --settings-preview-card: rgba(7,47,90,0.98);
      --settings-preview-button: #ff8800;
      --settings-preview-button-text: #102033;
    }
    body.gator-time-mode {
      --settings-panel-bg: linear-gradient(180deg, rgba(18,18,18,0.97) 0%, rgba(5,5,5,0.98) 100%);
      --settings-panel-border: rgba(197,179,88,0.34);
      --settings-panel-shadow: 0 26px 60px rgba(0,0,0,0.48), 0 0 0 1px rgba(197,179,88,0.08), 0 0 28px rgba(197,179,88,0.10);
      --settings-muted: rgba(255,248,220,0.78);
      --settings-accent: #C5B358;
      --settings-heading-accent: #fff1b5;
      --settings-accent-soft: rgba(197, 179, 88, 0.28);
      --settings-preview-bg: linear-gradient(145deg, #050505 0%, #171717 52%, #2a240f 100%);
      --settings-preview-sidebar: linear-gradient(180deg, rgba(197,179,88,0.32) 0%, rgba(197,179,88,0.12) 100%);
      --settings-preview-card: linear-gradient(180deg, rgba(43,36,15,0.96) 0%, rgba(20,20,20,0.98) 100%);
      --settings-preview-button: #C5B358;
      --settings-preview-button-text: #090909;
    }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: sans-serif;
    }
    .settings-shell {
      width: min(1120px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 40px;
    }
    .settings-panel {
      background: var(--settings-panel-bg);
      border: 1px solid var(--settings-panel-border);
      border-radius: 28px;
      box-shadow: var(--settings-panel-shadow);
      backdrop-filter: blur(12px);
      overflow: hidden;
    }
    .settings-header {
      padding: 28px 30px 22px;
      border-bottom: 1px solid var(--settings-panel-border);
      background:
        radial-gradient(circle at top left, var(--settings-accent-soft), transparent 40%),
        linear-gradient(135deg, rgba(255,255,255,0.08), transparent 70%);
    }
    .settings-kicker {
      margin: 0 0 8px 0;
      color: var(--settings-heading-accent);
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 0.78rem;
      font-weight: 800;
    }
    .settings-header h1 {
      margin: 0;
      font-size: clamp(1.8rem, 3vw, 2.5rem);
      line-height: 1.05;
    }
    .settings-header p {
      margin: 12px 0 0 0;
      max-width: 64ch;
      color: var(--settings-muted);
      line-height: 1.5;
    }
    .settings-crumbs {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 14px;
      font-size: 0.88rem;
      color: var(--settings-muted);
    }
    .settings-crumbs a {
      color: inherit;
      font-weight: 700;
      text-decoration: none;
    }
    .settings-crumbs a:hover {
      text-decoration: underline;
    }
    .settings-grid {
      display: grid;
      grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
      gap: 24px;
      padding: 24px 30px 30px;
      align-items: start;
    }
    .settings-card {
      background: rgba(255,255,255,0.40);
      border: 1px solid var(--settings-panel-border);
      border-radius: 22px;
      padding: 20px;
      box-sizing: border-box;
    }
    body.dark-mode .settings-card,
    body.musky-mode .settings-card,
    body.gator-time-mode .settings-card {
      background: rgba(255,255,255,0.03);
    }
    body.gator-time-mode .settings-panel {
      background:
        radial-gradient(circle at top right, rgba(197,179,88,0.12), transparent 26%),
        linear-gradient(180deg, rgba(20,20,20,0.98) 0%, rgba(6,6,6,0.98) 100%);
    }
    body.gator-time-mode .settings-header {
      background:
        radial-gradient(circle at top left, rgba(197,179,88,0.24), transparent 36%),
        linear-gradient(90deg, rgba(197,179,88,0.12) 0%, rgba(197,179,88,0.02) 26%, rgba(255,255,255,0.06) 52%, rgba(197,179,88,0.10) 100%);
      border-bottom-color: rgba(197,179,88,0.32);
    }
    body.gator-time-mode .settings-card {
      background:
        linear-gradient(180deg, rgba(255,255,255,0.04) 0%, rgba(197,179,88,0.04) 100%);
      border-color: rgba(197,179,88,0.24);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
    }
    .settings-card h2 {
      margin: 0 0 10px 0;
      font-size: 1.2rem;
    }
    .settings-card p {
      margin: 0;
      color: var(--settings-muted);
      line-height: 1.45;
    }
    .settings-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 18px;
    }
    .settings-select-group {
      margin-top: 18px;
    }
    .settings-select-group label {
      display: block;
      margin-bottom: 6px;
      font-size: 0.92rem;
      font-weight: 700;
    }
    .settings-select-group select {
      width: 100%;
      box-sizing: border-box;
    }
    .settings-link,
    .settings-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 12px;
      font-weight: 700;
      text-decoration: none;
      border: 1px solid transparent;
      cursor: pointer;
      transition: transform 0.18s ease, opacity 0.18s ease, background-color 0.18s ease;
    }
    .settings-link:hover,
    .settings-button:hover {
      transform: translateY(-1px);
    }
    .settings-button {
      background: var(--settings-accent);
      color: #fff;
    }
    body.gator-time-mode .settings-button {
      color: #111;
    }
    .settings-link {
      border-color: var(--settings-panel-border);
      color: inherit;
      background: rgba(255,255,255,0.18);
    }
    body.gator-time-mode .settings-link {
      background: linear-gradient(180deg, rgba(197,179,88,0.14) 0%, rgba(197,179,88,0.06) 100%);
      border-color: rgba(197,179,88,0.30);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
    }
    .theme-list {
      display: grid;
      gap: 12px;
      margin-top: 18px;
    }
    .theme-option {
      display: block;
      border: 1px solid var(--settings-panel-border);
      border-radius: 18px;
      padding: 14px;
      cursor: pointer;
      background: rgba(255,255,255,0.28);
      transition: transform 0.18s ease, border-color 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease;
    }
    body.dark-mode .theme-option,
    body.musky-mode .theme-option,
    body.gator-time-mode .theme-option {
      background: rgba(255,255,255,0.02);
    }
    body.gator-time-mode .theme-option {
      background:
        linear-gradient(180deg, rgba(197,179,88,0.08) 0%, rgba(255,255,255,0.02) 100%);
      border-color: rgba(197,179,88,0.20);
    }
    .theme-option:hover {
      transform: translateY(-1px);
      border-color: var(--settings-accent);
    }
    .theme-option.is-selected {
      border-color: var(--settings-accent);
      box-shadow: 0 0 0 3px var(--settings-accent-soft);
    }
    body.gator-time-mode .theme-option.is-selected {
      box-shadow: 0 0 0 3px rgba(197,179,88,0.18), 0 10px 24px rgba(0,0,0,0.24);
    }
    .theme-option input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .theme-option-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }
    .theme-option-title {
      font-weight: 800;
      font-size: 1rem;
    }
    .theme-option-note {
      color: var(--settings-muted);
      font-size: 0.92rem;
      line-height: 1.4;
    }
    .mini-swatches {
      display: flex;
      gap: 7px;
      margin-top: 12px;
    }
    .mini-swatches span {
      width: 18px;
      height: 18px;
      border-radius: 999px;
      border: 1px solid rgba(0,0,0,0.10);
      box-shadow: inset 0 1px 1px rgba(255,255,255,0.28);
    }
    .preview-area {
      display: grid;
      gap: 14px;
    }
    .preview-header {
      display: flex;
      align-items: end;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .preview-header h2 {
      margin: 0;
      font-size: 1.2rem;
    }
    .preview-header p {
      margin: 6px 0 0 0;
      color: var(--settings-muted);
    }
    .save-status {
      min-height: 1.2em;
      color: var(--settings-accent);
      font-size: 0.92rem;
      font-weight: 700;
    }
    .preview-stage {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    }
    .theme-preview {
      border: 1px solid var(--settings-panel-border);
      border-radius: 24px;
      overflow: hidden;
      background: var(--settings-preview-bg);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
    }
    .theme-preview-shell {
      display: grid;
      grid-template-columns: 88px minmax(0, 1fr);
      min-height: 220px;
    }
    .theme-preview-sidebar {
      padding: 14px 12px;
      background: var(--settings-preview-sidebar);
      border-right: 1px solid rgba(255,255,255,0.06);
    }
    .preview-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 24px;
      padding: 0 10px;
      border-radius: 999px;
      background: rgba(255,255,255,0.18);
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    body.gator-time-mode .preview-badge {
      background: rgba(197,179,88,0.18);
      color: #f7efc4;
      border: 1px solid rgba(197,179,88,0.22);
    }
    .preview-dots {
      display: grid;
      gap: 8px;
      margin-top: 14px;
    }
    .preview-dots span {
      width: 100%;
      height: 10px;
      border-radius: 999px;
      background: rgba(255,255,255,0.22);
    }
    .theme-preview-main {
      padding: 16px;
      color: inherit;
    }
    .preview-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }
    .preview-title {
      font-size: 1rem;
      font-weight: 800;
    }
    .preview-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      background: rgba(255,255,255,0.10);
      font-size: 0.78rem;
      font-weight: 700;
    }
    .preview-main-card {
      margin-top: 16px;
      padding: 16px;
      border-radius: 18px;
      background: var(--settings-preview-card);
      box-shadow: 0 12px 24px rgba(0,0,0,0.12);
    }
    body.gator-time-mode .preview-main-card {
      box-shadow: 0 14px 28px rgba(0,0,0,0.32), inset 0 1px 0 rgba(255,255,255,0.04);
      border: 1px solid rgba(197,179,88,0.16);
    }
    .preview-main-card h3 {
      margin: 0 0 8px 0;
      font-size: 1rem;
    }
    .preview-main-card p {
      margin: 0;
      color: var(--settings-muted);
      line-height: 1.45;
      font-size: 0.9rem;
    }
    .preview-stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 14px;
    }
    .preview-stat {
      padding: 12px;
      border-radius: 14px;
      background: rgba(255,255,255,0.08);
    }
    .preview-stat .label {
      display: block;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      opacity: 0.72;
    }
    .preview-stat .value {
      display: block;
      margin-top: 6px;
      font-size: 0.95rem;
      font-weight: 800;
    }
    .preview-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      margin-top: 14px;
      padding: 0 14px;
      border-radius: 12px;
      background: var(--settings-preview-button);
      color: var(--settings-preview-button-text);
      font-weight: 800;
      font-size: 0.88rem;
    }
    .theme-preview-catalog {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    }
    .catalog-card {
      border: 1px solid var(--settings-panel-border);
      border-radius: 18px;
      padding: 12px;
      background: rgba(255,255,255,0.16);
    }
    body.gator-time-mode .catalog-card {
      background: linear-gradient(180deg, rgba(197,179,88,0.10) 0%, rgba(255,255,255,0.03) 100%);
    }
    .catalog-card .catalog-title {
      font-weight: 800;
      font-size: 0.92rem;
    }
    .catalog-card .catalog-copy {
      margin-top: 6px;
      color: var(--settings-muted);
      font-size: 0.84rem;
      line-height: 1.35;
    }
    .preview-light {
      --settings-preview-bg: rgba(245,247,245,0.94);
      --settings-preview-sidebar: rgba(46, 125, 50, 0.12);
      --settings-preview-card: rgba(255,255,255,0.96);
      --settings-preview-button: #2e7d32;
      --settings-preview-button-text: #ffffff;
      color: #102214;
    }
    .preview-dark {
      --settings-preview-bg: rgba(13,16,19,0.98);
      --settings-preview-sidebar: rgba(124, 207, 132, 0.12);
      --settings-preview-card: rgba(30,34,38,0.98);
      --settings-preview-button: #7ccf84;
      --settings-preview-button-text: #0d1013;
      color: #f4f7f5;
    }
    .preview-musky {
      --settings-preview-bg: rgba(0,27,58,0.98);
      --settings-preview-sidebar: rgba(255,136,0,0.16);
      --settings-preview-card: rgba(7,47,90,0.98);
      --settings-preview-button: #ff8800;
      --settings-preview-button-text: #102033;
      color: #f4f7fb;
    }
    .preview-gator {
      --settings-preview-bg: linear-gradient(145deg, #050505 0%, #171717 52%, #2a240f 100%);
      --settings-preview-sidebar: linear-gradient(180deg, rgba(197,179,88,0.34) 0%, rgba(197,179,88,0.12) 100%);
      --settings-preview-card: linear-gradient(180deg, rgba(43,36,15,0.96) 0%, rgba(20,20,20,0.98) 100%);
      --settings-preview-button: #C5B358;
      --settings-preview-button-text: #090909;
      color: #fff4c2;
    }
    @media (max-width: 920px) {
      .settings-shell {
        width: min(100%, calc(100% - 20px));
        padding: 18px 0 26px;
      }
      .settings-grid {
        grid-template-columns: 1fr;
        padding: 18px;
      }
      .settings-header {
        padding: 22px 18px 18px;
      }
    }
  </style>
  <script>
    const themeCsrfToken = <?= json_encode($themeCsrfToken) ?>;

    function setSelectedThemeOption(theme) {
      document.querySelectorAll('.theme-option').forEach((option) => {
        option.classList.toggle('is-selected', option.dataset.theme === theme);
      });
      document.querySelectorAll('.theme-option .preview-badge').forEach((badge) => {
        badge.textContent = 'Preview';
      });
      const activeBadge = document.querySelector('.theme-option[data-theme="' + theme + '"] .preview-badge');
      if (activeBadge) activeBadge.textContent = 'Active';
      const themeSelect = document.getElementById('theme-select');
      if (themeSelect) themeSelect.value = theme;
    }

    function updatePreviewHeading(theme) {
      const title = document.getElementById('active-theme-name');
      if (!title) return;
      const option = document.querySelector('.theme-option[data-theme="' + theme + '"] .theme-option-title');
      title.textContent = option ? option.textContent : 'Theme Preview';
    }

    function setSaveStatus(message, isError) {
      const status = document.getElementById('save-status');
      if (!status) return;
      status.textContent = message || '';
      status.style.opacity = message ? '1' : '0';
      status.style.color = isError ? '#d32f2f' : '';
    }

    function changeTheme(theme) {
      localStorage.setItem('selectedTheme', theme);
      document.body.className = theme;
      setSelectedThemeOption(theme);
      updatePreviewHeading(theme);
      setSaveStatus('Saving theme...', false);

      const fd = new FormData();
      fd.append('theme', theme);
      fd.append('csrf_token', themeCsrfToken);

      fetch('', { method: 'POST', body: fd })
        .then((response) => {
          return response.json();
        })
        .then((payload) => {
          if (!payload || !payload.success) {
            const code = payload && payload.error ? payload.error : 'theme_save_failed';
            throw new Error(code);
          }
          setSaveStatus('Theme saved.', false);
          window.setTimeout(() => {
            if (document.body.className === theme) {
              setSaveStatus('', false);
            }
          }, 1600);
        })
        .catch((error) => {
          if (error && error.message === 'invalid_csrf') {
            setSaveStatus('Security token expired. Reload the page and try again.', true);
            return;
          }
          if (error && error.message === 'mysql_unavailable') {
            setSaveStatus('MariaDB is unavailable for theme saves.', true);
            return;
          }
          setSaveStatus('Could not save theme to MariaDB.', true);
        });
    }

    window.addEventListener('load', function() {
      const savedTheme = <?= json_encode($theme) ?>;
      document.body.className = savedTheme;
      const selectedInput = document.querySelector('input[name="theme-choice"][value="' + savedTheme + '"]');
      if (selectedInput) selectedInput.checked = true;
      setSelectedThemeOption(savedTheme);
      updatePreviewHeading(savedTheme);
      setSaveStatus('', false);
    });
  </script>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
  <div class="settings-shell">
    <div class="settings-panel">
      <div class="settings-header">
        <div class="settings-crumbs">
          <a href="index.php">Preferences</a>
          <span>/</span>
          <span>Set Themes</span>
        </div>
        <p class="settings-kicker">Preferences</p>
        <h1>Choose how Musky should look<?= $firstName ? ', ' . htmlspecialchars($firstName) : '' ?>.</h1>
        <p>
          Theme selection now lives as its own preference pane so we can add more user settings later without crowding this workflow.
          Pick a look, glance at the preview, and Musky updates right away.
        </p>
      </div>

      <div class="settings-grid">
        <section class="settings-card">
          <h2>Theme</h2>
          <p>Choose a preset and Musky will apply it immediately. The larger panel on the right gives a quick feel for the layout, cards, and action buttons.</p>

          <div class="settings-select-group">
            <label for="theme-select">🎨 Theme Select:</label>
            <select id="theme-select" onchange="changeTheme(this.value)">
              <option value="light-mode">Light</option>
              <option value="dark-mode">Dark</option>
              <option value="musky-mode">Musky Mode</option>
              <option value="gator-time-mode">Gator Time</option>
            </select>
          </div>

          <div class="theme-list">
            <?php foreach ($themeOptions as $themeKey => $themeMeta): ?>
              <?php
                $previewClass = 'preview-light';
                if ($themeKey === 'dark-mode') {
                    $previewClass = 'preview-dark';
                } elseif ($themeKey === 'musky-mode') {
                    $previewClass = 'preview-musky';
                } elseif ($themeKey === 'gator-time-mode') {
                    $previewClass = 'preview-gator';
                }
              ?>
              <label class="theme-option<?= $theme === $themeKey ? ' is-selected' : '' ?>" data-theme="<?= htmlspecialchars($themeKey) ?>">
                <input
                  type="radio"
                  name="theme-choice"
                  value="<?= htmlspecialchars($themeKey) ?>"
                  <?= $theme === $themeKey ? 'checked' : '' ?>
                  onchange="changeTheme(this.value)"
                />
                <div class="theme-option-head">
                  <span class="theme-option-title"><?= htmlspecialchars($themeMeta['label']) ?></span>
                  <span class="preview-badge"><?= $theme === $themeKey ? 'Active' : 'Preview' ?></span>
                </div>
                <div class="theme-option-note"><?= htmlspecialchars($themeMeta['note']) ?></div>
                <div class="mini-swatches <?= htmlspecialchars($previewClass) ?>">
                  <span style="background: var(--settings-preview-bg);"></span>
                  <span style="background: var(--settings-preview-card);"></span>
                  <span style="background: var(--settings-preview-button);"></span>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="settings-actions">
            <a class="settings-link" href="index.php">Back to Preferences</a>
            <a class="settings-link" href="../index.php">Back to Hub</a>
          </div>
        </section>

        <section class="preview-area">
          <div class="settings-card">
            <div class="preview-header">
              <div>
                <h2 id="active-theme-name">Theme Preview</h2>
                <p>A lightweight sample of the Musky shell so you can make the choice quickly.</p>
              </div>
              <div id="save-status" class="save-status" aria-live="polite"></div>
            </div>

            <div class="preview-stage">
              <div class="theme-preview">
                <div class="theme-preview-shell">
                  <div class="theme-preview-sidebar">
                    <span class="preview-badge">Musky</span>
                    <div class="preview-dots">
                      <span></span>
                      <span style="width: 76%;"></span>
                      <span style="width: 64%;"></span>
                    </div>
                  </div>
                  <div class="theme-preview-main">
                    <div class="preview-topbar">
                      <div class="preview-title">Device Hub</div>
                      <div class="preview-pill">Ready</div>
                    </div>
                    <div class="preview-main-card">
                      <h3>Assigned Devices</h3>
                      <p>Your cards, panels, and action buttons will inherit the selected look throughout Musky.</p>
                      <div class="preview-stats">
                        <div class="preview-stat">
                          <span class="label">Open</span>
                          <span class="value">12 Tickets</span>
                        </div>
                        <div class="preview-stat">
                          <span class="label">Loaners</span>
                          <span class="value">4 Ready</span>
                        </div>
                      </div>
                      <div class="preview-button">Open Tool</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="theme-preview-catalog">
                <div class="catalog-card">
                  <div class="catalog-title">Cards</div>
                  <div class="catalog-copy">See how panel surfaces and contrast feel before committing.</div>
                </div>
                <div class="catalog-card">
                  <div class="catalog-title">Buttons</div>
                  <div class="catalog-copy">The action color updates with each theme so the CTA tone is obvious.</div>
                </div>
                <div class="catalog-card">
                  <div class="catalog-title">Sidebar</div>
                  <div class="catalog-copy">The mini left rail hints at how navigation and labels will read.</div>
                </div>
                <div class="catalog-card">
                  <div class="catalog-title">Readability</div>
                  <div class="catalog-copy">A quick check for light, dark, Musky, and Gator contrast levels.</div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
