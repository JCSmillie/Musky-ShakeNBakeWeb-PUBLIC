<?php
// ============================================================================
// MUSKY - Musky_Reboot_iPad.php (v3 UX Edition)
// ----------------------------------------------------------------------------
// PURPOSE:
//   Submit a "REBOOTME" errand (single or multiple iPads) and monitor
//   until completion. Provides visual + audible success feedback,
//   optional auto-close, and clean Musky styling.
//
// DEPENDS:
//   • ../../Functions/nora_connect.php
//   • ../check_access.php
// ----------------------------------------------------------------------------
// AUTHOR: SmillieWare / Gateway Project
// ============================================================================

date_default_timezone_set('America/New_York');
session_start();
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';

// -----------------------------------------------------------------------------
// 1️⃣ Validate serial(s) + session
// -----------------------------------------------------------------------------
if (empty($_GET['serial'])) {
    die("<h3 style='color:red;'>❌ Missing device serial number(s).</h3>");
}

$user = $_SESSION['musky_user']['email'] ?? 'unknown';
$serials = array_filter(array_map('trim', explode(',', $_GET['serial']))); // support multiple

// -----------------------------------------------------------------------------
// 2️⃣ Connect to NORA
// -----------------------------------------------------------------------------
$pdo = nora_connect();

$errands = [];
foreach ($serials as $serial) {
    // Get device info
    $stmt = $pdo->prepare("SELECT serial_number, deviceudid, owner_id, last_seen 
                           FROM devices WHERE serial_number = ? LIMIT 1");
    $stmt->execute([$serial]);
    $device = $stmt->fetch();
    if (!$device) continue;

    // Reuse existing REBOOTME task if still active
    $stmt = $pdo->prepare("
        SELECT * FROM nora_errands
         WHERE DeviceSerial=? AND MOSBasicRequest='REBOOTME'
           AND Status NOT IN ('Complete','Failed','Cancelled','Rejected')
         ORDER BY SubmissionDateTime DESC LIMIT 1
    ");
    $stmt->execute([$serial]);
    $existing = $stmt->fetch();

    if ($existing) {
        $errands[$serial] = $existing['ErrandID'];
        continue;
    }

    // Create new errand
    $stmt = $pdo->prepare("
        INSERT INTO nora_errands
        (TaskPriority, Status, SubmissionDateTime, MOSBasicRequest,
         DeviceSerial, UDID, Submitter, DeviceOwner, ExtraDataField01)
        VALUES (5,'submitted',NOW(),'REBOOTME',?,?,?,?,?)
    ");
    $stmt->execute([
        $device['serial_number'],
        $device['deviceudid'],
        $user,
        $device['owner_id'],
        json_encode(['last_seen' => $device['last_seen']])
    ]);
    $errands[$serial] = $pdo->lastInsertId();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>📲 iPad Reboot Monitor</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body {
    font-family: "Segoe UI", Roboto, sans-serif;
    background: linear-gradient(180deg, #fdfdfd 0%, #f0f3f8 100%);
    margin: 0; padding: 40px;
    color: #222;
}
.container {
    max-width: 640px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 30px 40px;
    text-align: center;
}
h1 { font-size: 1.8em; margin-bottom: 5px; }
.sub { color: #666; margin-bottom: 25px; }
.status-box {
    display: inline-block;
    border-radius: 10px;
    padding: 14px 22px;
    font-size: 1.1em;
    font-weight: bold;
    color: white;
    background: #999;
    transition: background 0.3s ease, transform 0.3s ease;
}
.status-ok { background: #28a745; transform: scale(1.05); }
.status-fail { background: #dc3545; transform: scale(1.05); }
.status-processing { background: #ffc107; color: #222; }
.spinner {
    border: 4px solid #eee;
    border-top: 4px solid #0078d7;
    border-radius: 50%;
    width: 28px; height: 28px;
    animation: spin 1s linear infinite;
    margin: 10px auto;
}
@keyframes spin { 100% { transform: rotate(360deg); } }
.details { text-align: left; font-size: 0.9em; color: #555;
    margin-top: 20px; border-top: 1px solid #ddd; padding-top: 10px; }
footer { margin-top: 30px; font-size: 0.85em; color: #888; }
button {
    background: #0078d7; color: white; border: none;
    padding: 8px 14px; border-radius: 6px; cursor: pointer; margin-top: 20px;
}
button:hover { background: #005a9e; }
label { font-size: 0.9em; color: #444; display: block; margin-top: 10px; }
</style>
<script>
const errands = <?= json_encode($errands) ?>;
let countdown = 120;
let interval;

function playSuccessSound() {
    const audio = new Audio("../assets/success-chime.mp3");
    audio.volume = 0.6;
    audio.play().catch(() => {});
}

function pollAll() {
    let done = 0, total = Object.keys(errands).length;
    Object.entries(errands).forEach(([serial, id]) => {
        fetch("Reboot_StatusCheck.php?id=" + id)
        .then(res => res.json())
        .then(data => {
            const box = document.getElementById('status-' + serial);
            const msg = document.getElementById('msg-' + serial);
            const spinner = document.getElementById('spin-' + serial);
            const autoClose = document.getElementById('autoClose');

            box.textContent = data.status.toUpperCase();
            box.className = "status-box";
            spinner.style.display = "block";

            if (data.status.match(/complete/i)) {
                box.classList.add("status-ok");
                msg.textContent = "✅ Command successfully sent!";
                spinner.style.display = "none";
                playSuccessSound();
                done++;
            } else if (data.status.match(/failed/i)) {
                box.classList.add("status-fail");
                msg.textContent = "❌ Failed to send command.";
                spinner.style.display = "none";
                done++;
            } else {
                box.classList.add("status-processing");
                msg.textContent = "⌛ Waiting (" + countdown + "s left)";
            }

            if (done === total) {
                clearInterval(interval);
                if (autoClose.checked) setTimeout(() => window.close(), 2000);
            }
        })
        .catch(e => console.error("Status check failed:", e));
    });

    countdown -= 3;
    if (countdown <= 0) {
        clearInterval(interval);
        document.querySelectorAll("[id^='msg-']").forEach(el => {
            el.textContent = "⏱️ Timeout after 120s with no update.";
        });
    }
}

window.onload = function() {
    interval = setInterval(pollAll, 3000);
    pollAll();
};
</script>
</head>
<body>
<div class="container">
    <h1>📲 Reboot iPad<?= count($errands) > 1 ? 's' : '' ?></h1>
    <p class="sub">
        <?= count($errands) > 1 ? "Multiple devices are being processed." : "Reboot request initiated." ?>
    </p>

    <?php foreach ($errands as $serial => $eid): ?>
    <div style="margin-bottom:25px;">
        <div id="status-<?= $serial ?>" class="status-box status-processing">submitted</div>
        <div id="spin-<?= $serial ?>" class="spinner"></div>
        <p id="msg-<?= $serial ?>">⌛ Monitoring (up to 120 seconds)</p>
        <div class="details">
            <p><strong>Serial:</strong> <?= htmlspecialchars($serial) ?></p>
            <p><strong>Errand ID:</strong> <?= htmlspecialchars($eid) ?></p>
        </div>
    </div>
    <?php endforeach; ?>

    <label><input type="checkbox" id="autoClose" checked> Close this window automatically on success</label>
    <button onclick="window.close()">Close Window</button>
    <footer>SmillieWare Musky • Powered by NORA</footer>
</div>
</body>
</html>
