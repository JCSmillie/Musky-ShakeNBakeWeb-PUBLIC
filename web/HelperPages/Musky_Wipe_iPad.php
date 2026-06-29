<?php
// ============================================================================
// MUSKY - Musky_Wipe_iPad.php (UI Front-End Only)
// ---------------------------------------------------------------------------
// PURPOSE:
//   Pretty UI wrapper for the iPad wipe workflow.
//
// THIS FILE:
//   - Handles GET input: ?serial=...&no_reassign=1
//   - Loads theme from Musky session
//   - Calls Musky_Wipe_iPad.Worker.php for all logic
//   - Renders device cards + logs, top bar, auto-close, status bar.
//
// BACKEND ENGINE:
//   Musky_Wipe_iPad.Worker.php
// ============================================================================
session_start();
// -----------------------------------------------------------------------------
// SECURITY BASELINE (MANDATORY):
//   This page is a direct web entrypoint and therefore must always bootstrap
//   Musky's centralized access middleware before doing any workflow work.
//   That middleware enforces login/session rules and standardized user context.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

// Theme from the shared preference layer so this helper follows the same
// universal theme contract as the main Musky pages.
$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';

// Default refresh interval (seconds) — worker may also use this value.
$REFRESH_INTERVAL = 5;

// Raw input
$serialParam = $_GET['serial'] ?? '';
$serialParam = trim($serialParam);
$noReassign  = !empty($_GET['no_reassign']); // STORAGE mode if true
$ownerOverrideToken = trim((string)($_GET['owner_override_token'] ?? ''));
$launchContextToken = trim((string)($_GET['launch_context_token'] ?? ''));
$ownerOverrides = [];
if ($ownerOverrideToken !== '' && !empty($_SESSION['technology_marsh_wipe_overrides'][$ownerOverrideToken]['overrides'])) {
    $ownerOverrides = $_SESSION['technology_marsh_wipe_overrides'][$ownerOverrideToken]['overrides'];
}
if (!isset($_SESSION['technology_marsh_wipe_launch_contexts']) || !is_array($_SESSION['technology_marsh_wipe_launch_contexts'])) {
    $_SESSION['technology_marsh_wipe_launch_contexts'] = [];
}
$nowContext = time();
foreach ($_SESSION['technology_marsh_wipe_launch_contexts'] as $token => $payload) {
    $createdAt = (int)($payload['created_at'] ?? 0);
    if ($createdAt <= 0 || ($nowContext - $createdAt) > 1800) {
        unset($_SESSION['technology_marsh_wipe_launch_contexts'][$token]);
    }
}
$launchContext = [];
if ($launchContextToken !== '' && !empty($_SESSION['technology_marsh_wipe_launch_contexts'][$launchContextToken])) {
    $launchContext = (array)$_SESSION['technology_marsh_wipe_launch_contexts'][$launchContextToken];
}

// Per-device recovery actions
$retryOne = !empty($_GET['retry']);
$forceOne = !empty($_GET['force']);
$requestedSerials = array_values(array_unique(array_filter(
    preg_split('/\s*,\s*/', $serialParam)
)));

if ($serialParam !== '' && ($retryOne || $forceOne) && count($requestedSerials) === 1) {
    $_SESSION['musky_wipe_restart_request'] = [
        'serial' => $requestedSerials[0],
        'force' => $forceOne,
        'requested_at' => time(),
    ];

    $redirectParams = $_GET;
    unset($redirectParams['retry'], $redirectParams['force']);

    $redirectTarget = $_SERVER['PHP_SELF'] ?? 'Musky_Wipe_iPad.php';
    $redirectQuery = http_build_query($redirectParams);
    if ($redirectQuery !== '') {
        $redirectTarget .= '?' . $redirectQuery;
    }

    header('Location: ' . $redirectTarget);
    exit;
}

$restartRequest = [];
if ($serialParam !== '' && count($requestedSerials) === 1 && !empty($_SESSION['musky_wipe_restart_request'])) {
    $candidate = $_SESSION['musky_wipe_restart_request'];
    $candidateSerial = strtoupper(trim((string)($candidate['serial'] ?? '')));
    $currentSerial = strtoupper(trim((string)$requestedSerials[0]));
    if ($candidateSerial !== '' && $candidateSerial === $currentSerial) {
        $restartRequest = $candidate;
    }
    unset($_SESSION['musky_wipe_restart_request']);
}

// Include worker engine
require_once __DIR__ . '/Musky_Wipe_iPad.Worker.php';

// -----------------------------------------------------------------------------
// Local Helpers (UI Only)
// -----------------------------------------------------------------------------

// Small helper to build a safe HTML id from serial
function wipe_html_id_from_serial(string $serial): string {
    return 'log_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $serial);
}

// Tail last N lines from the per-serial log file
function tail_log(string $serial, int $lines = 120): string {
    $path = "/tmp/musky_wipe_" . preg_replace('/[^A-Za-z0-9_]/', '_', $serial) . ".log";
    if (!file_exists($path)) return "(no log yet)";

    $data = @file($path, FILE_IGNORE_NEW_LINES);
    if (!$data) return "(no log yet)";
    $slice = array_slice($data, max(0, count($data) - $lines));
    return implode("\n", $slice);
}

// Defaults
$workerResult    = null;
$serials         = [];
$perSerialStates = [];
$needsRefresh    = false;
$submitterUser   = $_SESSION['musky_user']['username']
    ?? $_SESSION['musky_user']['email']
    ?? 'Musky_Wipe_iPad.php';
$now             = time();

// Global summary flags, updated when we have states
$allDone   = false;
$anyFailed = false;

// If we have serials, run the worker; otherwise we'll show helper form
if ($serialParam !== '') {
    $workerResult = musky_wipe_worker_run($serialParam, $noReassign, $ownerOverrides, $restartRequest, $launchContext);

    $serials         = $workerResult['serials'] ?? [];
    $perSerialStates = $workerResult['perSerialStates'] ?? [];
    $needsRefresh    = !empty($workerResult['needsRefresh']);
    $submitterUser   = $workerResult['submitterUser'] ?? $submitterUser;
    $now             = $workerResult['now'] ?? $now;

    // Allow worker to override interval if it returns one
    if (isset($workerResult['refreshInterval']) && is_numeric($workerResult['refreshInterval'])) {
        $REFRESH_INTERVAL = (int)$workerResult['refreshInterval'];
    }

    // Compute summary counts
    $total      = is_array($perSerialStates) ? count($perSerialStates) : 0;
    $complete   = 0;
    $failed     = 0;
    $inProgress = 0;

    if (is_array($perSerialStates)) {
        foreach ($perSerialStates as $state) {
            $stage = $state['stage'] ?? 'unknown';
            if ($stage === 'complete') {
                $complete++;
            } elseif ($stage === 'failed') {
                $failed++;
            } else {
                $inProgress++;
            }
        }
    }

    $allDone   = ($total > 0 && $inProgress === 0);
    $anyFailed = ($failed > 0);
} else {
    // No serials yet — just helper form; summary values remain false/0.
    $total      = 0;
    $complete   = 0;
    $failed     = 0;
    $inProgress = 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>MUSKY - Wipe Devices</title>
    <link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
    <?php if ($serialParam !== '' && $needsRefresh): ?>
        <meta http-equiv="refresh" content="<?php echo (int)$REFRESH_INTERVAL; ?>">
    <?php endif; ?>
    <style>
        .wipe-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.2);
        }
        .light-mode .wipe-topbar {
            background: #f1f1f1;
            border-color: #ccc;
        }
        .dark-mode .wipe-topbar {
            background: #1b1b1b;
            border-color: #333;
        }
        .musky-mode .wipe-topbar {
            background: linear-gradient(90deg, #002855, #ff8800);
            color: #fff;
            border-bottom: 2px solid #ff8800;
        }
        .gator-time-mode .wipe-topbar {
            background: linear-gradient(90deg, #000000, #1a1a1a, #FFB612);
            color: #FFB612;
            border-bottom: 2px solid #FFB612;
        }
        .wipe-topbar-title {
            font-weight: 600;
            font-size: 1rem;
        }
        .wipe-topbar-right {
            display: flex;
            gap: 12px;
            align-items: center;
            font-size: 0.85rem;
        }
        .wipe-autoclose-label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }
        .wipe-theme-label {
            opacity: 0.8;
            font-style: italic;
        }

        .wipe-page {
            padding: 20px;
        }
        .wipe-title {
            margin-top: 0;
        }
        .wipe-meta {
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: #aaa;
        }
        .light-mode .wipe-meta {
            color: #555;
        }

        .wipe-statusbar {
            margin-top: 8px;
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
        }
        .dark-mode .wipe-statusbar {
            background: #1e1e1e;
            color: #ddd;
        }
        .light-mode .wipe-statusbar {
            background: #f1f1f1;
            color: #333;
        }
        .musky-mode .wipe-statusbar {
            background: rgba(0,40,85,0.5);
            color: #f2f2f2;
        }
        .gator-time-mode .wipe-statusbar {
            background: #111111;
            color: #FFB612;
        }
        .wipe-status-main {
            font-weight: 500;
        }
        .wipe-status-hint {
            font-size: 0.8rem;
            opacity: 0.85;
        }

        .wipe-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
            margin-top: 15px;
        }
        .wipe-card {
            background: #1e1e1e;
            border-radius: 10px;
            padding: 12px 14px;
            box-shadow: 0 0 8px rgba(0,0,0,0.6);
        }
        .light-mode .wipe-card {
            background: #ffffff;
            box-shadow: 0 0 6px rgba(0,0,0,0.15);
        }
        .musky-mode .wipe-card {
            background: #00261a;
            box-shadow: 0 0 10px rgba(255,136,0,0.3);
        }
        .gator-time-mode .wipe-card {
            background: #111111;
            box-shadow: 0 0 10px rgba(255,182,18,0.35);
        }

        .wipe-card-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 6px;
        }
        .wipe-serial {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .wipe-asset-tag{
            font-size: 1.7rem;
            line-height: 1;
            font-weight: 900;
            letter-spacing: 0.02em;
        }
        .wipe-serial-sub{
            margin-top: 3px;
            font-size: 0.8rem;
            opacity: 0.82;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .wipe-badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        .badge-mode-reassign { background: #264d26; color: #9eff9e; }
        .badge-mode-noreassign { background: #4d2626; color: #ff9e9e; }
        .badge-stage {
            background: #333;
            color: #ddd;
            margin-left: 6px;
        }
        .badge-complete { background: #1f4d2b; color: #a0ffb4; }
        .badge-failed { background: #5c1c1c; color: #ffb0b0; }

        .light-mode .badge-stage {
            background: #e0e0e0;
            color: #333;
        }

        .wipe-kv {
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        .wipe-kv span.label { color: #aaa; }
        .light-mode .wipe-kv span.label { color: #555; }

        /* Hybrid Progress Widget ------------------------------------------------- */
        .wipe-progress-wrapper {
            margin: 10px 0;
        }
        .wipe-progress-bar {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            overflow: hidden;
            background: #333;
            position: relative;
        }
        .light-mode .wipe-progress-bar {
            background: #e0e0e0;
        }
        .musky-mode .wipe-progress-bar {
            background: #00160f;
        }
        .gator-time-mode .wipe-progress-bar {
            background: #222;
        }

        .wipe-progress-fill {
            height: 100%;
            width: 0;
            border-radius: 999px;
            transition: width 0.3s ease;
            background: #4CAF50;
        }
        .musky-mode .wipe-progress-fill {
            background: linear-gradient(90deg, #002855, #ff8800);
        }
        .gator-time-mode .wipe-progress-fill {
            background: linear-gradient(90deg, #FFB612, #e6a800);
        }

        .wipe-progress-label {
            margin-top: 4px;
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .wipe-steps-row {
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .wipe-step-badge {
            border-radius: 999px;
            padding: 2px 6px;
            font-size: 0.7rem;
            border: 1px solid #555;
            opacity: 0.7;
        }
        .wipe-step-done {
            background: #264d26;
            color: #9eff9e;
            border-color: #264d26;
            opacity: 1;
        }
        .wipe-step-current {
            background: #0077cc;
            color: #fff;
            border-color: #0077cc;
            opacity: 1;
        }
        .wipe-step-pending {
            background: transparent;
            color: #aaa;
        }
        .light-mode .wipe-step-pending {
            color: #555;
            border-color: #ccc;
        }

        .musky-mode .wipe-step-current {
            background: #ff8800;
            border-color: #ff8800;
            color: #fff;
        }
        .gator-time-mode .wipe-step-current {
            background: #FFB612;
            border-color: #FFB612;
            color: #000;
        }

        .wipe-footer {
            margin-top: 15px;
            font-size: 0.8rem;
            color: #777;
        }
        .light-mode .wipe-footer {
            color: #555;
        }

        .wipe-summary {
            font-size: 0.9rem;
            margin-top: 5px;
        }
        .wipe-helper-card {
            max-width: 600px;
            margin-top: 20px;
            background: #222;
            padding: 15px;
            border-radius: 8px;
        }
        .light-mode .wipe-helper-card {
            background: #ffffff;
            box-shadow: 0 0 6px rgba(0,0,0,0.15);
        }
        .wipe-helper-card input[type=text] {
            width: 100%;
            padding: 6px;
            box-sizing: border-box;
        }
        .wipe-helper-card button {
            margin-top: 12px;
            padding: 8px 16px;
            cursor: pointer;
        }
        .wipe-handoff-note {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 700;
        }
        .dark-mode .wipe-handoff-note {
            background: #1d2a24;
            color: #d6f0df;
            border: 1px solid rgba(131, 181, 145, 0.35);
        }
        .light-mode .wipe-handoff-note {
            background: #edf8f0;
            color: #214233;
            border: 1px solid rgba(33,66,51,.18);
        }
        .musky-mode .wipe-handoff-note {
            background: rgba(0, 40, 85, 0.35);
            color: #f6f4ee;
            border: 1px solid rgba(255, 136, 0, 0.35);
        }
        .gator-time-mode .wipe-handoff-note {
            background: #151515;
            color: #FFB612;
            border: 1px solid rgba(255, 182, 18, 0.35);
        }

        /* NEW: per-card action buttons (Retry / Force / Done) -------------------- */
        .wipe-actions {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .wipe-actions .wipe-danger {
            border: 1px solid rgba(255,255,255,0.2);
        }
        .dark-mode .wipe-actions .wipe-danger {
            background: #8b1c1c;
            color: #fff;
        }
        .light-mode .wipe-actions .wipe-danger {
            background: #b33a3a;
            color: #fff;
        }
        .musky-mode .wipe-actions .wipe-danger {
            background: #ff3300;
            color: #fff;
        }
        .gator-time-mode .wipe-actions .wipe-danger {
            background: #FFB612;
            color: #000;
        }

        /* Debug log toggle ------------------------------------------------------- */
        .wipe-debug-toggle {
            margin-top: 6px;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #555;
            background: transparent;
            color: inherit;
            cursor: pointer;
        }
        .wipe-debug-toggle:hover {
            background: rgba(255,255,255,0.05);
        }
        .light-mode .wipe-debug-toggle:hover {
            background: #f1f1f1;
        }

        pre.wipe-log {
            background: #000;
            padding: 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            max-height: 220px;
            overflow-y: auto;
            white-space: pre-wrap;
            margin-top: 6px;
        }
        .light-mode pre.wipe-log {
            background: #f7f7f7;
            color: #222;
        }
        .musky-mode pre.wipe-log {
            background: #00160f;
            color: #e6f5e6;
        }
        .gator-time-mode pre.wipe-log {
            background: #000000;
            color: #FFB612;
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($theme); ?>">

<div class="wipe-topbar">
    <div class="wipe-topbar-left">
        <span class="wipe-topbar-title">🧨 iPad Wipe Progress</span>
    </div>
    <div class="wipe-topbar-right">
        <label class="wipe-autoclose-label">
            <input type="checkbox" id="autoCloseCheckbox">
            Close when done
        </label>
        <span class="wipe-theme-label">
            Theme: <?php echo htmlspecialchars($theme); ?>
        </span>
    </div>
</div>

<div class="wipe-page">

    <?php if ($serialParam === ''): ?>
        <!-- No serials provided – helper form -->
        <h1 class="wipe-title">🔧 MUSKY - iPad Wipe Helper</h1>
        <div class="wipe-meta">
            This page orchestrates NORA/MOSBasic errands to wipe and (optionally) reassign devices.<br>
            Provide one or more serials, comma separated.
        </div>
        <div class="wipe-helper-card">
            <form method="get">
                <label>Serial(s):<br>
                    <input type="text" name="serial" placeholder="F9FG4ELYQ1GC,F2QQDRYPC7" required>
                </label>
                <br><br>
                <label>
                    <input type="checkbox" name="no_reassign" value="1">
                    Wipe ONLY (do not reassign, clear tags / STORAGE mode)
                </label>
                <br><br>
                <button type="submit" class="action-button">Start Wipe Flow</button>
            </form>
        </div>
    <?php else: ?>
        <!-- Active wipe workflow -->
        <h1 class="wipe-title">🧨 MUSKY - iPad Wipe Helper</h1>
        <div class="wipe-meta">
            Mode:
            <strong>
                <?php echo $noReassign
                    ? 'STORAGE Mode – Wipe Only / Do Not Reassign (Clear Tags)'
                    : 'Wipe + Reassign + Tag Restore'; ?>
            </strong><br>

            <?php /* NEW: hide serial numbers from end-user UI */ ?>
            <?php /* Serials: <code><?php echo htmlspecialchars(implode(', ', $serials)); ?></code><br> */ ?>

            Submitter: <code><?php echo htmlspecialchars($submitterUser); ?></code><br>
            Page refreshes every <?php echo (int)$REFRESH_INTERVAL; ?>s while any device is in progress.
        </div>
        <?php if (!empty($workerResult['launchContextUsed'])): ?>
            <div class="wipe-handoff-note">
                Technology Marsh already completed a fresh Resolve Devices pass for this batch, so the helper skipped the duplicate startup INV_LOOKUP.
            </div>
        <?php endif; ?>

        <!-- Mini Status Bar -->
        <div class="wipe-statusbar">
            <span class="wipe-status-main" id="wipeStatusText">
                Devices: <?php echo (int)$total; ?> total •
                <?php echo (int)$complete; ?> complete •
                <?php echo (int)$inProgress; ?> in progress •
                <?php echo (int)$failed; ?> failed
            </span>
            <span class="wipe-status-hint" id="wipeAutoCloseHint">
                <?php if ($allDone && !$anyFailed): ?>
                    All devices complete. This window will close automatically.
                <?php elseif ($allDone && $anyFailed): ?>
                    All devices finished, but some failed — window will stay open.
                <?php else: ?>
                    Auto-close will trigger once all devices complete successfully.
                <?php endif; ?>
            </span>
        </div>

        <div class="wipe-grid">
            <?php if (!is_array($perSerialStates) || empty($perSerialStates)): ?>
                <div class="wipe-card">
                    <div class="wipe-card-header">
                        <div class="wipe-serial">No Devices</div>
                        <div>
                            <span class="wipe-badge badge-stage badge-failed">Stage: failed</span>
                        </div>
                    </div>
                    <div class="wipe-summary">
                        ❌ <strong>Failure:</strong> Worker returned no device state entries. (UI safeguard)
                    </div>
                    <div class="wipe-kv">
                        <span class="label">Input serial(s):</span>
                        <span><?php echo htmlspecialchars($serialParam); ?></span>
                    </div>
                </div>
            <?php else: ?>

                <?php
                // IMPORTANT UI PATCH:
                //   DO NOT trust array keys from $perSerialStates.
                //   Always derive $ser from the state itself.
                foreach ($perSerialStates as $state):
                    $ser = $state['serial'] ?? 'UNKNOWN';
                ?>
                    <?php
                        $stage   = $state['stage'] ?? 'unknown';
                        $mode    = $state['mode'] ?? 'reassign';
                        $bl      = $state['baseline'] ?? [];
                        $assetTag = trim((string)($bl['asset_tag'] ?? ''));
                        $failure = $state['failure_reason'] ?? null;

                        $badgeStageClass = 'badge-stage';
                        if ($stage === 'complete') $badgeStageClass .= ' badge-complete';
                        if ($stage === 'failed')   $badgeStageClass .= ' badge-failed';

                        $modeBadgeClass = $mode === 'reassign'
                            ? 'wipe-badge badge-mode-reassign'
                            : 'wipe-badge badge-mode-noreassign';

                        // Steps configuration for hybrid progress bar
                        $steps = [
                            1 => 'Inventory Lookup',
                            2 => 'Baseline Snapshot',
                            3 => 'CLEARCOMMANDSALL',
                            4 => 'WIPEME',
                            5 => 'Post-Wipe Cooldown',
                            6 => 'Re-Inventory',
                            7 => $mode === 'reassign' ? 'Reassign Owner' : 'Storage Window',
                            8 => $mode === 'reassign' ? 'Restore Tags' : 'Clear Tags',
                        ];
                        $totalSteps = count($steps);

                        // Map worker "stage" to a current step index
                        $currentStep = 1;
                        switch ($stage) {
                            case 'bootstrap':
                                $currentStep = 2; // inventory done, baseline in hand
                                break;
                            case 'clear_pending':
                            case 'limbo_pending':
                            case 'clear_wait':
                                $currentStep = 3;
                                break;
                            case 'wipe_pending':
                                $currentStep = 4;
                                break;
                            case 'wipe_wait':
                                $currentStep = 5;
                                break;
                            case 'post_inv':
                                $currentStep = 6;
                                break;
                            case 'reassign_wait':
                            case 'assign_pending':
                                $currentStep = 7;
                                break;
                            case 'tags_pending':
                                $currentStep = 8;
                                break;
                            case 'complete':
                                $currentStep = $totalSteps;
                                break;
                            case 'failed':
                                $currentStep = max(1, $totalSteps - 1);
                                break;
                            default:
                                $currentStep = 1;
                        }

                        if ($stage === 'complete') {
                            $completedSteps = $totalSteps;
                        } elseif ($stage === 'failed') {
                            $completedSteps = max(0, $currentStep - 1);
                        } else {
                            $completedSteps = max(0, $currentStep - 1);
                        }

                        $progressPercent = ($totalSteps > 0)
                            ? (int)round(($completedSteps / $totalSteps) * 100)
                            : 0;

                        $currentLabel = $steps[$currentStep] ?? 'In Progress';

                        // For BDA-2 countdown display
                        $countdownText = '';
                        if ($stage === 'reassign_wait') {
                            $waitStr = $state['reassign']['wait_started_at'] ?? null;
                            $waitTs  = $waitStr ? strtotime($waitStr) : null;
                            $waitTotal = $workerResult['reassign_wait'] ?? (5 * 60);
                            if ($waitTs) {
                                $elapsed   = time() - $waitTs;
                                $remaining = max(0, $waitTotal - $elapsed);
                                $countdownText = " (BDA-2: ~{$remaining}s remaining before ASSIGNME/storage action)";
                            }
                        }

                        $logId = wipe_html_id_from_serial($ser);
                    ?>
                    <div class="wipe-card">
                        <div class="wipe-card-header">
                            <div class="wipe-serial">
                                <div class="wipe-asset-tag"><?php echo htmlspecialchars($assetTag !== '' ? $assetTag : 'Unknown Asset'); ?></div>
                                <div class="wipe-serial-sub">Serial <?php echo htmlspecialchars($ser); ?></div>
                            </div>
                            <div>
                                <span class="<?php echo $modeBadgeClass; ?>">
                                    <?php echo $mode === 'reassign' ? 'Reassign Mode' : 'STORAGE Mode'; ?>
                                </span>
                                <span class="wipe-badge <?php echo $badgeStageClass; ?>">
                                    Stage: <?php echo htmlspecialchars($stage); ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($ser === 'UNKNOWN'): ?>
                            <div class="wipe-summary">
                                ⚠️ <strong>UI Warning:</strong> Worker state missing a serial field. This card may not operate correctly.
                            </div>
                        <?php endif; ?>

                        <div class="wipe-kv">
                            <span class="label">User:</span>
                            <span><?php echo htmlspecialchars($bl['owner_mosyle_id'] ?? 'Unknown'); ?></span>
                        </div>
                        <?php if (!empty($bl['owner_override_message'])): ?>
                            <div class="wipe-kv">
                                <span class="label">Owner Note:</span>
                                <span><?php echo htmlspecialchars((string)$bl['owner_override_message']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="wipe-kv">
                            <span class="label">Tags:</span>
                            <span><?php echo htmlspecialchars($bl['tags'] ?? ''); ?></span>
                        </div>
                        <div class="wipe-kv">
                            <span class="label">Last Seen (baseline):</span>
                            <span><?php echo htmlspecialchars($bl['last_seen'] ?? 'Unknown'); ?></span>
                        </div>
                        <div class="wipe-kv">
                            <span class="label">Last Enrollment (baseline):</span>
                            <span><?php echo htmlspecialchars(musky_wipe_format_timestamp($bl['date_enroll'] ?? null)); ?></span>
                        </div>
                        <div class="wipe-kv">
                            <span class="label">UDID:</span>
                            <span><?php echo htmlspecialchars($bl['udid'] ?? 'Unknown'); ?></span>
                        </div>

                        <!-- Hybrid progress widget -->
                        <div class="wipe-progress-wrapper">
                            <div class="wipe-progress-bar">
                                <div class="wipe-progress-fill" style="width: <?php echo (int)$progressPercent; ?>%;"></div>
                            </div>
                            <div class="wipe-progress-label">
                                Step <?php echo (int)$currentStep; ?> of <?php echo (int)$totalSteps; ?>:
                                <?php echo htmlspecialchars($currentLabel); ?>
                            </div>
                            <div class="wipe-steps-row">
                                <?php foreach ($steps as $num => $label): ?>
                                    <?php
                                        $cls = 'wipe-step-badge wipe-step-pending';
                                        if ($num <= $completedSteps) {
                                            $cls = 'wipe-step-badge wipe-step-done';
                                        } elseif ($num === $currentStep && $stage !== 'complete' && $stage !== 'failed') {
                                            $cls = 'wipe-step-badge wipe-step-current';
                                        }
                                    ?>
                                    <span class="<?php echo $cls; ?>">
                                        <?php echo (int)$num; ?>. <?php echo htmlspecialchars($label); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($stage === 'failed' && $failure): ?>
                            <div class="wipe-summary">
                                ❌ <strong>Failure:</strong> <?php echo htmlspecialchars($failure); ?>
                            </div>

                            <?php /* NEW: Retry + Force buttons on FAILED only */ ?>
                            <div class="wipe-actions">
                                <button type="button"
                                        class="action-button"
                                        onclick="retryDevice('<?php echo htmlspecialchars($ser); ?>')">
                                    🔁 Retry
                                </button>

                                <button type="button"
                                        class="action-button wipe-danger"
                                        onclick="forceDevice('<?php echo htmlspecialchars($ser); ?>')">
                                    ⚠️ FORCE IT
                                </button>
                            </div>

                        <?php elseif ($stage === 'complete'): ?>
                            <div class="wipe-summary">
                                ✅ <strong>Done:</strong> Wipe workflow completed for this device.
                            </div>

                            <?php /* NEW: Done button on COMPLETE only (hides card for focus) */ ?>
                            <div class="wipe-actions">
                                <button type="button"
                                        class="action-button"
                                        onclick="markDone(this)">
                                    ✅ Done
                                </button>
                            </div>

                        <?php else: ?>
                            <div class="wipe-summary">
                                ⏳ Workflow is in progress<?php echo htmlspecialchars($countdownText); ?>.
                            </div>
                        <?php endif; ?>

                        <!-- Debug log toggle (hidden by default) -->
                        <button type="button"
                                class="wipe-debug-toggle"
                                onclick="toggleWipeLog('<?php echo $logId; ?>', this)">
                            Show debug log
                        </button>
                        <pre id="<?php echo $logId; ?>" class="wipe-log hidden"><?php echo htmlspecialchars(tail_log($ser)); ?></pre>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
        </div>

        <div class="wipe-footer">
            Started: <?php echo date('Y-m-d H:i:s', $now); ?> (server time)<br>
            Each device writes debug log to <code>/tmp/musky_wipe_&lt;serial&gt;.log</code><br>
            State is tracked in <code>/tmp/musky_wipe_state_&lt;serial&gt;.json</code> per device.
        </div>
    <?php endif; ?>

</div>

<script>
// ---------------------------------------------------------------------------
// Auto-close + status behavior
// ---------------------------------------------------------------------------
(function() {
    const autoCloseCheckbox = document.getElementById('autoCloseCheckbox');
    if (!autoCloseCheckbox) return;

    const STORAGE_KEY = 'muskyWipeAutoClose';

    // Initial setting from localStorage (default: true)
    const stored = window.localStorage ? localStorage.getItem(STORAGE_KEY) : null;
    const autoCloseEnabled = (stored === null) ? true : (stored === 'true');
    autoCloseCheckbox.checked = autoCloseEnabled;

    autoCloseCheckbox.addEventListener('change', function() {
        if (!window.localStorage) return;
        localStorage.setItem(STORAGE_KEY, this.checked ? 'true' : 'false');
    });

    // These flags come from PHP summary (only meaningful when serials are present)
    const allDone   = <?php echo ($serialParam !== '' && $allDone) ? 'true' : 'false'; ?>;
    const anyFailed = <?php echo ($serialParam !== '' && $anyFailed) ? 'true' : 'false'; ?>;

    const hintEl = document.getElementById('wipeAutoCloseHint');

    if (!allDone) {
        // Still in progress — nothing special to do here.
        return;
    }

    // All devices finished at this point.
    if (anyFailed) {
        if (hintEl) {
            hintEl.textContent = 'All devices finished, but some failed — window will stay open.';
        }
        return;
    }

    // All done, no failures.
    if (!autoCloseCheckbox.checked) {
        if (hintEl) {
            hintEl.textContent = 'All devices completed successfully. Auto-close is disabled.';
        }
        return;
    }

    // All done, no failures, auto-close ON → show countdown + close.
    let remaining = 8; // seconds
    if (hintEl) {
        hintEl.textContent = 'All devices completed successfully. Closing in ' + remaining + 's …';
    }

    const timer = setInterval(function() {
        remaining--;
        if (hintEl) {
            if (remaining > 0) {
                hintEl.textContent = 'All devices completed successfully. Closing in ' + remaining + 's …';
            } else {
                hintEl.textContent = 'All devices completed successfully. Closing…';
            }
        }
        if (remaining <= 0) {
            clearInterval(timer);
            try {
                window.close();
            } catch (e) {
                // If the window wasn't script-opened, close might fail silently.
            }
        }
    }, 1000);
})();

// ---------------------------------------------------------------------------
// Debug log toggle per device
// ---------------------------------------------------------------------------
function toggleWipeLog(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    const isHidden = el.classList.contains('hidden');
    if (isHidden) {
        el.classList.remove('hidden');
        if (btn) btn.textContent = 'Hide debug log';
    } else {
        el.classList.add('hidden');
        if (btn) btn.textContent = 'Show debug log';
    }
}

// ---------------------------------------------------------------------------
// NEW: Retry / Force / Done actions
// ---------------------------------------------------------------------------
function retryDevice(serial) {
    const u = new URL(window.location.href);

    // Keep storage mode setting if present
    const noReassign = u.searchParams.get('no_reassign');
    u.searchParams.set('serial', serial);
    if (noReassign) u.searchParams.set('no_reassign', noReassign);

    u.searchParams.set('retry', '1');
    u.searchParams.delete('force');

    // This intentionally narrows scope to THIS ONE device
    window.location.href = u.toString();
}

function forceDevice(serial) {
    if (!confirm('FORCE IT will skip inventory/baseline and go straight to WIPE.\n\nProceed?')) return;

    const u = new URL(window.location.href);

    // Keep storage mode setting if present
    const noReassign = u.searchParams.get('no_reassign');
    u.searchParams.set('serial', serial);
    if (noReassign) u.searchParams.set('no_reassign', noReassign);

    u.searchParams.set('force', '1');
    u.searchParams.delete('retry');

    // This intentionally narrows scope to THIS ONE device
    window.location.href = u.toString();
}

function markDone(btn) {
    const card = btn.closest('.wipe-card');
    if (card) card.remove();
}
</script>

</body>
</html>
