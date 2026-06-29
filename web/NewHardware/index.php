<?php
declare(strict_types=1);

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/NewHardware.php';

if (!defined('NORA_CONNECT_NO_AUTO')) {
    define('NORA_CONNECT_NO_AUTO', true);
}
if (!defined('NORA_CONNECT_THROW')) {
    define('NORA_CONNECT_THROW', true);
}
require_once __DIR__ . '/../../Functions/nora_connect.php';

function new_hardware_intake_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function new_hardware_intake_clear_preview_except(?int $keepUnitId = null): void
{
    if (!isset($_SESSION['musky_new_hardware_preview']) || !is_array($_SESSION['musky_new_hardware_preview'])) {
        $_SESSION['musky_new_hardware_preview'] = [];
        return;
    }

    if ($keepUnitId === null) {
        $_SESSION['musky_new_hardware_preview'] = [];
        return;
    }

    foreach (array_keys($_SESSION['musky_new_hardware_preview']) as $unitId) {
        if ((int)$unitId !== $keepUnitId) {
            unset($_SESSION['musky_new_hardware_preview'][$unitId]);
        }
    }
}

function new_hardware_intake_status_class(string $status): string
{
    return match (strtoupper(trim($status))) {
        'AVAILABLE' => 'status-available',
        'IN_PROGRESS' => 'status-in-progress',
        'COMPLETED' => 'status-completed',
        default => 'status-available',
    };
}

$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['DEVICE_MANAGER'],
    [
        'response' => 'html',
        'status' => 403,
        'message' => 'Missing required Musky permissions.',
    ]
);

try {
    $pdo = nora_connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Nora database connection is unavailable.';
    exit;
}

musky_new_hardware_ensure_schema($pdo);

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$userEmail = $prefs['email'] ?? musky_new_hardware_actor_email();
$csrfToken = musky_csrf_token('new_hardware_intake');
$isUpperAdmin = in_array('ADMIN_PANEL', $allowed, true) || in_array('ALL_TOOLS', $allowed, true);

$flash = null;
$error = null;
$completed = null;
$sessionId = session_id();

$activeUnit = musky_new_hardware_fetch_active_claim_for_actor($pdo, (string)$userEmail, $sessionId);
if (!$activeUnit) {
    new_hardware_intake_clear_preview_except(null);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    musky_csrf_require('new_hardware_intake');
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'lookup_serial') {
        $result = musky_new_hardware_begin_claim(
            $pdo,
            (string)($_POST['serial_scan'] ?? ''),
            (string)$userEmail,
            $sessionId
        );

        if ($result['ok']) {
            $activeUnit = $result['unit'] ?? null;
            $keepUnitId = $activeUnit ? (int)($activeUnit['id'] ?? 0) : null;
            new_hardware_intake_clear_preview_except($keepUnitId ?: null);
            $flash = $result['message'];
            musky_activity_log([
                'event_type' => 'ACTION',
                'action_name' => 'NEW_HARDWARE_LOOKUP',
                'target_serials' => $activeUnit['serial_match_key'] ?? '',
            ]);
        } else {
            $error = $result['message'];
            if (!empty($result['unit']) && is_array($result['unit'])) {
                $activeUnit = $result['unit'];
            }
        }
    } elseif ($action === 'preview_claim') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $unit = musky_new_hardware_fetch_unit_by_id($pdo, $unitId);
        if (!$unit) {
            $error = 'That machine is no longer available.';
            new_hardware_intake_clear_preview_except(null);
            $activeUnit = null;
        } else {
            $result = musky_new_hardware_prepare_confirmation(
                $pdo,
                $unit,
                (string)($_POST['asset_tag'] ?? ''),
                (string)($_POST['assignment_input'] ?? ''),
                (string)$userEmail
            );

            if ($result['ok']) {
                $_SESSION['musky_new_hardware_preview'][$unitId] = $result;
                $flash = $result['message'];
                $activeUnit = musky_new_hardware_fetch_unit_by_id($pdo, $unitId) ?: $unit;
                musky_activity_log([
                    'event_type' => 'ACTION',
                    'action_name' => 'NEW_HARDWARE_PREVIEW',
                    'target_serials' => $activeUnit['serial_match_key'] ?? '',
                    'target_asset_tags' => $result['asset_tag'] ?? '',
                ]);
            } else {
                unset($_SESSION['musky_new_hardware_preview'][$unitId]);
                $error = $result['message'];
                $activeUnit = $unit;
            }
        }
    } elseif ($action === 'confirm_complete') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $preview = $_SESSION['musky_new_hardware_preview'][$unitId] ?? null;
        if (!is_array($preview)) {
            $error = 'The confirmation details expired. Run the owner check again.';
        } else {
            $result = musky_new_hardware_commit_confirmation(
                $pdo,
                $unitId,
                $preview,
                (string)$userEmail,
                $sessionId
            );

            if ($result['ok']) {
                unset($_SESSION['musky_new_hardware_preview'][$unitId]);
                $completed = $result;
                $flash = $result['message'];
                $activeUnit = null;
                musky_activity_log([
                    'event_type' => 'ACTION',
                    'action_name' => 'NEW_HARDWARE_COMPLETE',
                    'target_serials' => $result['unit']['serial_match_key'] ?? '',
                    'target_asset_tags' => $result['unit']['asset_tag'] ?? '',
                ]);
            } else {
                $error = $result['message'];
                $activeUnit = musky_new_hardware_fetch_unit_by_id($pdo, $unitId);
            }
        }
    } elseif ($action === 'release_claim') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $result = musky_new_hardware_release_claim($pdo, $unitId, (string)$userEmail, false);
        if ($result['ok']) {
            unset($_SESSION['musky_new_hardware_preview'][$unitId]);
            $flash = $result['message'];
            $activeUnit = null;
            musky_activity_log([
                'event_type' => 'ACTION',
                'action_name' => 'NEW_HARDWARE_RELEASE',
            ]);
        } else {
            $error = $result['message'];
            $activeUnit = musky_new_hardware_fetch_unit_by_id($pdo, $unitId);
        }
    }
}

$activePreview = null;
if ($activeUnit) {
    $activePreview = $_SESSION['musky_new_hardware_preview'][(int)$activeUnit['id']] ?? null;
    if (!is_array($activePreview)) {
        $activePreview = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>New Hardware Intake</title>
  <link rel="stylesheet" href="../theme.css?theme=<?= new_hardware_intake_h($theme) ?>">
  <style>
    body {
      margin: 0;
      font-family: sans-serif;
      background: #edf3f7;
      color: #153049;
    }
    body.dark-mode {
      background: #10161d;
      color: #f2f6fb;
    }
    body.musky-mode {
      background: #09233b;
      color: #f2f8ff;
    }
    .page-shell {
      max-width: 1160px;
      margin: 0 auto;
      padding: 24px;
    }
    .hero,
    .panel {
      background: rgba(255,255,255,0.92);
      border: 1px solid rgba(21,48,73,0.10);
      border-radius: 22px;
      box-shadow: 0 18px 40px rgba(18,34,51,0.08);
      padding: 24px;
      margin-bottom: 20px;
    }
    body.dark-mode .hero,
    body.dark-mode .panel,
    body.musky-mode .hero,
    body.musky-mode .panel {
      background: rgba(8,14,21,0.9);
      border-color: rgba(255,255,255,0.08);
      box-shadow: 0 18px 40px rgba(0,0,0,0.34);
    }
    .hero-top,
    .panel-top,
    .button-row,
    .identity-row,
    .fact-grid,
    .two-up {
      display: grid;
      gap: 14px;
    }
    .hero-top {
      grid-template-columns: minmax(320px, 1.4fr) minmax(220px, 0.8fr);
      align-items: start;
    }
    .button-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
    }
    .two-up {
      grid-template-columns: minmax(320px, 0.95fr) minmax(340px, 1.05fr);
      align-items: start;
    }
    .hero h1,
    .panel h2,
    .panel h3 {
      margin-top: 0;
    }
    .mini-note,
    .muted {
      opacity: 0.76;
    }
    .big-note {
      font-size: 1.05rem;
      line-height: 1.6;
    }
    form {
      margin: 0;
    }
    label {
      display: block;
      font-weight: 800;
      margin-bottom: 8px;
    }
    input[type="text"] {
      width: 100%;
      box-sizing: border-box;
      border-radius: 16px;
      border: 1px solid rgba(21,48,73,0.16);
      padding: 18px 18px;
      font: inherit;
      color: inherit;
      background: rgba(255,255,255,0.96);
    }
    input.scan-input {
      font-size: 1.45rem;
      letter-spacing: 0.03em;
      padding: 22px 20px;
    }
    body.dark-mode input[type="text"],
    body.musky-mode input[type="text"] {
      background: rgba(17,25,34,0.96);
      border-color: rgba(255,255,255,0.12);
      color: inherit;
    }
    .primary-btn,
    .secondary-btn,
    .danger-btn,
    .ghost-link {
      appearance: none;
      border: none;
      border-radius: 999px;
      padding: 12px 18px;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .primary-btn {
      background: #1f73e0;
      color: #fff;
    }
    .secondary-btn {
      background: rgba(31,115,224,0.13);
      color: inherit;
      border: 1px solid rgba(31,115,224,0.20);
    }
    .danger-btn {
      background: #c53b30;
      color: #fff;
    }
    .ghost-link {
      background: rgba(21,48,73,0.08);
      color: inherit;
    }
    .flash,
    .error {
      border-radius: 16px;
      padding: 15px 18px;
      margin-bottom: 18px;
      font-weight: 800;
    }
    .flash {
      background: rgba(23,148,84,0.12);
      border: 1px solid rgba(23,148,84,0.22);
      color: #08653b;
    }
    .error {
      background: rgba(197,59,48,0.12);
      border: 1px solid rgba(197,59,48,0.22);
      color: #8e1f15;
    }
    body.dark-mode .flash,
    body.musky-mode .flash {
      color: #b2efc7;
    }
    body.dark-mode .error,
    body.musky-mode .error {
      color: #ffb8b1;
    }
    .scan-tip,
    .owner-card,
    .unit-card,
    .completion-card {
      border-radius: 18px;
      padding: 18px;
      border: 1px solid rgba(21,48,73,0.10);
      background: rgba(255,255,255,0.72);
    }
    body.dark-mode .scan-tip,
    body.dark-mode .owner-card,
    body.dark-mode .unit-card,
    body.dark-mode .completion-card,
    body.musky-mode .scan-tip,
    body.musky-mode .owner-card,
    body.musky-mode .unit-card,
    body.musky-mode .completion-card {
      background: rgba(255,255,255,0.03);
      border-color: rgba(255,255,255,0.08);
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 0.86rem;
      font-weight: 800;
      letter-spacing: 0.03em;
    }
    .status-available {
      background: rgba(23,148,84,0.14);
      color: #0b673d;
    }
    .status-in-progress {
      background: rgba(207,126,15,0.16);
      color: #8a5400;
    }
    .status-completed {
      background: rgba(31,115,224,0.14);
      color: #0c4fa8;
    }
    body.dark-mode .status-available,
    body.musky-mode .status-available {
      color: #b2efc7;
    }
    body.dark-mode .status-in-progress,
    body.musky-mode .status-in-progress {
      color: #ffd596;
    }
    body.dark-mode .status-completed,
    body.musky-mode .status-completed {
      color: #b7d4ff;
    }
    .identity-row {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      margin-top: 14px;
    }
    .fact-grid {
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      margin-top: 14px;
    }
    .fact {
      padding: 12px 13px;
      border-radius: 14px;
      background: rgba(21,48,73,0.05);
    }
    body.dark-mode .fact,
    body.musky-mode .fact {
      background: rgba(255,255,255,0.04);
    }
    .fact strong {
      display: block;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      opacity: 0.74;
      margin-bottom: 6px;
    }
    .confirm-panel {
      margin-top: 16px;
      padding-top: 16px;
      border-top: 1px solid rgba(21,48,73,0.10);
    }
    body.dark-mode .confirm-panel,
    body.musky-mode .confirm-panel {
      border-top-color: rgba(255,255,255,0.08);
    }
    @media (max-width: 940px) {
      .hero-top,
      .two-up {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="<?= new_hardware_intake_h($theme) ?>">
  <div class="page-shell">
    <section class="hero">
      <div class="hero-top">
        <div>
          <div class="mini-note">Scanner Workflow</div>
          <h1>New Hardware Intake</h1>
          <p class="big-note">Scan the Apple box serial, let Musky strip the leading <strong>S</strong>, then scan the asset tag and assignment. Musky runs the Nora user check before you confirm the machine and move on.</p>
        </div>
        <div class="button-row" style="justify-content:flex-end;">
          <a class="ghost-link" href="../index.php">Back to Musky Hub</a>
          <?php if ($isUpperAdmin): ?>
            <a class="secondary-btn" href="../admin/NewHardwareAdmin.php">Open Admin View</a>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($flash): ?>
      <div class="flash"><?= new_hardware_intake_h($flash) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="error"><?= new_hardware_intake_h($error) ?></div>
    <?php endif; ?>

    <?php if ($completed && is_array($completed['unit'] ?? null)): ?>
      <?php $doneUnit = $completed['unit']; $doneOwner = is_array($completed['owner'] ?? null) ? $completed['owner'] : []; ?>
      <section class="panel completion-card">
        <div class="button-row" style="justify-content:space-between;">
          <h2 style="margin:0;">Machine Completed</h2>
          <a class="primary-btn" href="index.php">Next Machine</a>
        </div>
        <div class="identity-row">
          <div class="fact">
            <strong>Serial</strong>
            <?= new_hardware_intake_h($doneUnit['serial_match_key'] ?? '') ?>
          </div>
          <div class="fact">
            <strong>Asset Tag</strong>
            <?= new_hardware_intake_h($doneUnit['asset_tag'] ?? '') ?>
          </div>
          <div class="fact">
            <strong>Assignment</strong>
            <?= new_hardware_intake_h($doneUnit['assignment_input'] ?? '') ?>
          </div>
          <div class="fact">
            <strong>Processed By</strong>
            <?= new_hardware_intake_h($doneUnit['completed_by'] ?? '') ?>
          </div>
        </div>
        <div class="fact-grid">
          <div class="fact"><strong>Owner</strong><?= new_hardware_intake_h($doneOwner['full_name'] ?? $doneUnit['owner_name'] ?? 'Unknown') ?></div>
          <div class="fact"><strong>Email</strong><?= new_hardware_intake_h($doneOwner['email'] ?? $doneUnit['owner_email'] ?? 'Unknown') ?></div>
          <div class="fact"><strong>Type</strong><?= new_hardware_intake_h($doneOwner['user_type'] ?? $doneUnit['owner_user_type'] ?? 'Unknown') ?></div>
          <div class="fact"><strong>Grade</strong><?= new_hardware_intake_h($doneOwner['grade'] ?? $doneUnit['owner_grade'] ?? 'Unknown') ?></div>
          <div class="fact"><strong>School ID</strong><?= new_hardware_intake_h($doneOwner['district_id'] ?? $doneUnit['owner_district_id'] ?? 'Unknown') ?></div>
          <div class="fact"><strong>Owner Status</strong><?= new_hardware_intake_h($doneOwner['status'] ?? $doneUnit['owner_status'] ?? 'Unknown') ?></div>
        </div>
        <div class="mini-note" style="margin-top:14px;">
          The live Nora device row will be filled in on the next normal Nora/Mosyle sync.
        </div>
      </section>
    <?php endif; ?>

    <div class="two-up">
      <section class="panel">
        <div class="panel-top">
          <h2>Step 1: Match the Box</h2>
          <p class="mini-note">Apple scans often include a leading <strong>S</strong>. This intake page removes it automatically before matching against the admin-uploaded pool.</p>
        </div>

        <?php if (!$activeUnit): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= new_hardware_intake_h($csrfToken) ?>">
            <input type="hidden" name="action" value="lookup_serial">
            <label for="serial_scan">Scan Box Serial</label>
            <input id="serial_scan" class="scan-input" type="text" name="serial_scan" placeholder="Scan Apple box serial here" autocomplete="off" autofocus>
            <div class="button-row" style="margin-top:16px;">
              <button class="primary-btn" type="submit">Match Serial</button>
            </div>
          </form>
        <?php else: ?>
          <div class="unit-card">
            <div class="button-row" style="justify-content:space-between;">
              <div>
                <div class="mini-note">Current Machine</div>
                <h3 style="margin:4px 0 0;">Serial <?= new_hardware_intake_h($activeUnit['serial_match_key'] ?? '') ?></h3>
              </div>
              <span class="status-pill <?= new_hardware_intake_h(new_hardware_intake_status_class((string)($activeUnit['status'] ?? ''))) ?>">
                <?= new_hardware_intake_h($activeUnit['status'] ?? '') ?>
              </span>
            </div>
            <div class="fact-grid">
              <div class="fact">
                <strong>Batch</strong>
                <?= new_hardware_intake_h($activeUnit['batch_label'] ?? '') ?>
              </div>
              <div class="fact">
                <strong>Scanned Value</strong>
                <?= new_hardware_intake_h($activeUnit['lookup_serial_raw'] ?: 'Waiting on scan') ?>
              </div>
              <div class="fact">
                <strong>Claimed By</strong>
                <?= new_hardware_intake_h($activeUnit['claimed_by'] ?: 'Unknown') ?>
              </div>
              <div class="fact">
                <strong>Claim Started</strong>
                <?= new_hardware_intake_h($activeUnit['claim_started_at'] ?: 'Just now') ?>
              </div>
            </div>
            <div class="button-row" style="margin-top:16px;">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= new_hardware_intake_h($csrfToken) ?>">
                <input type="hidden" name="action" value="release_claim">
                <input type="hidden" name="unit_id" value="<?= (int)$activeUnit['id'] ?>">
                <button class="danger-btn" type="submit">Release This Machine</button>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel">
        <h2>Step 2: Scan Asset Tag and Assignment</h2>
        <?php if (!$activeUnit): ?>
          <div class="scan-tip">
            <h3 style="margin-top:0;">Waiting on a serial match</h3>
            <p>Once the box serial matches the new hardware pool, this panel becomes the asset tag and assignment workflow.</p>
          </div>
        <?php else: ?>
          <?php
            $prefillAsset = is_array($activePreview) ? (string)($activePreview['asset_tag'] ?? '') : (string)($activeUnit['asset_tag'] ?? '');
            $prefillAssignment = is_array($activePreview) ? (string)($activePreview['assignment_input'] ?? '') : (string)($activeUnit['assignment_input'] ?? '');
          ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= new_hardware_intake_h($csrfToken) ?>">
            <input type="hidden" name="action" value="preview_claim">
            <input type="hidden" name="unit_id" value="<?= (int)$activeUnit['id'] ?>">

            <div style="margin-bottom:14px;">
              <label for="asset_tag">Asset Tag</label>
              <input id="asset_tag" class="scan-input" type="text" name="asset_tag" value="<?= new_hardware_intake_h($prefillAsset) ?>" placeholder="Scan asset tag" autocomplete="off" <?= $activePreview ? '' : 'autofocus' ?>>
            </div>

            <div style="margin-bottom:16px;">
              <label for="assignment_input">Assignment</label>
              <input id="assignment_input" class="scan-input" type="text" name="assignment_input" value="<?= new_hardware_intake_h($prefillAssignment) ?>" placeholder="Scan username or email" autocomplete="off">
            </div>

            <div class="button-row">
              <button class="primary-btn" type="submit">Run Owner Check</button>
            </div>
          </form>

          <?php if ($activePreview): ?>
            <?php $owner = is_array($activePreview['owner'] ?? null) ? $activePreview['owner'] : []; ?>
            <div class="owner-card" style="margin-top:18px;">
              <div class="button-row" style="justify-content:space-between;">
                <div>
                  <div class="mini-note">Confirmation Preview</div>
                  <h3 style="margin:4px 0 0;">Owner check passed</h3>
                </div>
                <span class="status-pill status-completed">READY TO CONFIRM</span>
              </div>

              <div class="identity-row">
                <div class="fact">
                  <strong>Serial</strong>
                  <?= new_hardware_intake_h($activeUnit['serial_match_key'] ?? '') ?>
                </div>
                <div class="fact">
                  <strong>Asset Tag</strong>
                  <?= new_hardware_intake_h($activePreview['asset_tag'] ?? '') ?>
                </div>
                <div class="fact">
                  <strong>Assignment</strong>
                  <?= new_hardware_intake_h($activePreview['assignment_input'] ?? '') ?>
                </div>
                <div class="fact">
                  <strong>UserCheck Errand</strong>
                  <?= new_hardware_intake_h((string)($activePreview['usercheck_errand_id'] ?? '')) ?>
                </div>
              </div>

              <div class="fact-grid">
                <div class="fact"><strong>Full Name</strong><?= new_hardware_intake_h($owner['full_name'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>Email</strong><?= new_hardware_intake_h($owner['email'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>User Type</strong><?= new_hardware_intake_h($owner['user_type'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>Grade</strong><?= new_hardware_intake_h($owner['grade'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>School ID</strong><?= new_hardware_intake_h($owner['district_id'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>Status</strong><?= new_hardware_intake_h($owner['status'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>Mosyle ID</strong><?= new_hardware_intake_h($owner['mosyle_id'] ?? 'Unknown') ?></div>
                <div class="fact"><strong>Last Seen</strong><?= new_hardware_intake_h($owner['last_seen'] ?? 'Unknown') ?></div>
              </div>

              <?php if (!empty($activePreview['iiqsync_errand_id']) || !empty($activePreview['iiqsync_message'])): ?>
                <div class="confirm-panel">
                  <div class="mini-note">
                    <?php if (!empty($activePreview['iiqsync_errand_id'])): ?>
                      IIQ Sync Errand #<?= new_hardware_intake_h((string)$activePreview['iiqsync_errand_id']) ?>
                    <?php endif; ?>
                    <?php if (!empty($activePreview['iiqsync_message'])): ?>
                      <?= new_hardware_intake_h((string)$activePreview['iiqsync_message']) ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>

              <div class="confirm-panel">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= new_hardware_intake_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="confirm_complete">
                  <input type="hidden" name="unit_id" value="<?= (int)$activeUnit['id'] ?>">
                  <div class="button-row">
                    <button class="primary-btn" type="submit" autofocus>Confirm and Finish Machine</button>
                    <a class="ghost-link" href="index.php">Start Over</a>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <script>
    const activePreview = <?= $activePreview ? 'true' : 'false' ?>;
    const activeUnit = <?= $activeUnit ? 'true' : 'false' ?>;
    if (!activeUnit) {
      document.getElementById('serial_scan')?.focus();
    } else if (!activePreview) {
      document.getElementById('asset_tag')?.focus();
    }
  </script>
</body>
</html>
