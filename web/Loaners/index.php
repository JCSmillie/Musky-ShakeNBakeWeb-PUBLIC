<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../check_access.php';
require_once('../loaner_constants.php');
require_once('loaner_helpers.php');
require_once('loaner_utils.php');

$tool_required = 'LOANER_MGMT';
$allowed = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? []);
if (!in_array($tool_required, $allowed) && !in_array('ALL_TOOLS', $allowed)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$tool_required}";
    exit;
}

$theme = $row['theme'] ?? 'light';
$email = $_SESSION['musky_user']['email'] ?? '';
try {
    $db = new PDO("sqlite:$SQLITE_PATH");
    $stmt = $db->prepare("SELECT theme FROM musky_users WHERE email = ?");
    $stmt->execute([$email]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $theme = $row['theme'] ?? 'light-mode';
    }
} catch (Exception $e) {}

$LoanerPool2Search = '';
$currentPoolLabel = '';
$choice = $_POST['pool'] ?? '';
$csvFilePath = '/tmp/loaner_devices.csv';
$parsedData = ['headers' => [], 'rows' => []];
if (file_exists($csvFilePath)) {
    $csvContent = file_get_contents($csvFilePath);
    $parsedData = parseCSVFromShellOutput($csvContent);
}
$hiddenColumns = ['UDID', 'Serial Number', 'Mosyle User', 'IIQ User', 'Enrollment Type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Loaner Device Manager</title>
<link rel="icon" type="image/png" href="../musky_favicon.png">
<link rel="stylesheet" href="../theme.css">
<link rel="stylesheet" href="loaner_styles.css?v=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>

<body class="<?= htmlspecialchars($theme) ?>">
<div id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">Loading data... Please be patient.</div>
</div>

<div class="top-controls" style="display:flex;justify-content:space-between;align-items:center;margin:1em 0;">
    <button onclick="window.location.href='../index.php'" style="background:#ccc;padding:0.5em 1em;border:none;border-radius:5px;cursor:pointer;">🔙 Return to Launch</button>
</div>

<button id="problemButton" onclick="submitProblem()">Problem?</button>
<img src="../mascot.png" id="mascot" class="corner-image" alt="Mascot">
<div class="content">
    <div class="main-container">
        <h1>
    Loaner Devices<?=
        isset($_SESSION['last_pool_choice'], LOANER_POOLS[$_SESSION['last_pool_choice']])
        ? ': ' . htmlspecialchars(LOANER_POOLS[$_SESSION['last_pool_choice']])
        : ''
    ?>
</h1>

        <?php if (!empty($parsedData['rows'])): ?>
        <form id="actionForm" method="post">
            <input type="hidden" name="udids" id="hiddenUdids" />
            <input type="hidden" name="serials" id="hiddenSerials" />
            <input type="hidden" name="tags" id="hiddenTags" />
        </form>
        <table border="1" cellspacing="0" cellpadding="5" id="deviceTable">
            <thead>
                <tr>
                    <th>Select</th>
                    <?php foreach ($parsedData['headers'] as $header): ?>
                        <th class="<?= in_array($header, $hiddenColumns) ? 'hide-col' : '' ?>">
                            <?= htmlspecialchars($header) ?>
                        </th>
                        <?php if ($header == 'Asset Tag'): ?><th>Device User</th><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($parsedData['rows'] as $row): ?>
                <tr>
                    <td><input type="checkbox" class="device-checkbox"
                        data-udid="<?= htmlspecialchars($row[array_search('UDID', $parsedData['headers'])]) ?>"
                        data-serial="<?= htmlspecialchars($row[array_search('Serial Number', $parsedData['headers'])]) ?>"
                        data-tag="<?= htmlspecialchars($row[array_search('Asset Tag', $parsedData['headers'])]) ?>"
                        onchange="updateMultiSelectState()"></td>

                    <?php foreach ($row as $i => $cell): ?>
                        <?php
                        $header = $parsedData['headers'][$i];
                        $class = in_array($header, $hiddenColumns) ? 'hide-col' : '';

                        if ($header == 'Last Check In Date') {
                            echo '<td class="' . $class . '">' . htmlspecialchars(decodeLastCheckIn($cell)) . '</td>';
                        } elseif ($header == 'Needs iOS Update') {
                            echo '<td class="' . $class . '">' . decodeIosUpdate($cell) . '</td>';
                        } elseif ($header == 'Last Ip of Connection') {
                            list($displayText, $tooltip) = decodeIpCampusStatus($cell);
                            echo '<td class="' . $class . '" title="' . htmlspecialchars($tooltip) . '">' . htmlspecialchars($displayText) . '</td>';
                        } elseif ($header == 'Ticket Number') {
                            echo '<td class="' . $class . '">' . (trim($cell) === 'NoInfo' ? '' : htmlspecialchars($cell)) . '</td>';
                        } elseif ($header == 'Asset Tag') {
                            echo '<td class="' . $class . '"><a href="../DeviceManager/index.php?assettag=' . urlencode($cell) . '" target="_blank">' . htmlspecialchars($cell) . '</a></td>';
                        } else {
                            echo '<td class="' . $class . '">' . htmlspecialchars($cell) . '</td>';
                        }

                        if ($header == 'Asset Tag') {
                            $mosyleUser = trim($row[array_search('Mosyle User', $parsedData['headers'])]);
                            $iiqUser = trim($row[array_search('IIQ User', $parsedData['headers'])]);
                            $ticketNumber = trim($row[array_search('Ticket Number', $parsedData['headers'])]);
                            list($deviceUserCell, $deviceUserClass) = decodeDeviceUserStatus($mosyleUser, $iiqUser, $ticketNumber);
                            echo "<td class='$deviceUserClass'>$deviceUserCell</td>";
                        }
                        ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p>No data loaded yet.</p>
        <?php endif; ?>
    </div>
    <div class="sidebar">
        <div id="lastRefreshed" style="font-weight:bold; margin-bottom: 10px;">Last Refreshed: Not yet</div>
        <div class="sidebar-section" id="poolSection">
            <h2><?= htmlspecialchars($currentPoolLabel ?: 'Select Pool') ?></h2>
            <form id="poolForm" method="post">
                <select name="pool" id="poolSelect" onchange="selectPool()">
                    <option value="">--Choose Pool--</option>
                    <?php foreach (LOANER_POOLS as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($choice == $key) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($key) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="clearPoolSelection()" style="margin-top: 10px;">Clear Pool</button>
            </form>
        </div>
        <hr>
        <div class="sidebar-section" id="actionSection">
            <button form="actionForm" name="multi_wipe" id="multiWipe" disabled class="action-button-red">Mass Wipe Selected</button>
            <button form="actionForm" name="multi_verify" id="multiVerify" disabled class="action-button-orange">Verify Assignment</button>
            <button form="actionForm" name="multi_message" id="multiMessage" disabled class="action-button-orange">Message User</button>
            <button type="button" onclick="reloadData()" class="action-button-red">Reload Data</button>
            <button id="toggleMoreDataBtn" onclick="toggleMoreData()">More Data</button>
            <button onclick="toggleDebug()">Toggle Debug</button>
        </div>
        <hr>
    </div>
</div>

<script>
function updateMultiSelectState() {
    const checkboxes = document.querySelectorAll('.device-checkbox');
    let udids = [], serials = [], tags = [];

    checkboxes.forEach(cb => {
        if (cb.checked) {
            udids.push(cb.getAttribute('data-udid'));
            serials.push(cb.getAttribute('data-serial'));
            tags.push(cb.getAttribute('data-tag'));
        }
    });

    document.getElementById('hiddenUdids').value = udids.join(',');
    document.getElementById('hiddenSerials').value = serials.join(',');
    document.getElementById('hiddenTags').value = tags.join(',');

    const hasSelection = udids.length > 0;
    document.getElementById('multiWipe').disabled = !hasSelection;
    document.getElementById('multiVerify').disabled = !hasSelection;
    document.getElementById('multiMessage').disabled = !hasSelection;
}

function updateLastRefreshed() {
    const refreshed = document.getElementById('lastRefreshed');
    if (refreshed) {
        refreshed.textContent = 'Last Refreshed: ' + new Date().toLocaleTimeString();
    }
}

function toggleMoreData() {
    const hiddenCols = document.querySelectorAll('.hide-col');
    hiddenCols.forEach(col => {
        col.style.display = (col.style.display === 'none' || col.style.display === '') ? 'table-cell' : 'none';
    });

    const btn = document.getElementById('toggleMoreDataBtn');
    if (btn && hiddenCols.length) {
        btn.textContent = (hiddenCols[0].style.display === 'table-cell') ? 'Less Data' : 'More Data';
    }
}

function toggleDebug() {
    let pane = document.getElementById('debugPane');
    if (!pane) {
        pane = document.createElement('div');
        pane.id = 'debugPane';
        pane.style.display = 'none';
        pane.innerHTML = '<h3>Debug Output</h3><pre>No debug output loaded.</pre>';
        document.body.appendChild(pane);
    }
    pane.style.display = (pane.style.display === 'none') ? 'block' : 'none';
}

function selectPool() {
    const poolValue = document.getElementById('poolSelect').value;
    if (poolValue === '') return;
    startLiveLoadingStatus('fetch_loanerdata.php?pool=' + encodeURIComponent(poolValue));
}
function clearPoolSelection() {
    const poolSelect = document.getElementById('poolSelect');
    if (poolSelect) {
        poolSelect.value = '';
        document.getElementById('poolForm').submit();
    }
}

function reloadData() {
    showLoadingSpinner();
    setTimeout(() => {
        document.getElementById('poolForm').submit();
    }, 100);
}

function showLoadingSpinner() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'flex';
}
function hideLoadingSpinner() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'none';
}

function startLiveLoadingStatus(url) {
    showLoadingSpinner();

    fetch(url)
    .then(response => {
        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        function readChunk() {
            return reader.read().then(({ done, value }) => {
                if (done) {
                    hideLoadingSpinner();
                    location.reload();
                    return;
                }

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();

                lines.forEach(line => {
                    if (line.includes('<<MUSKY-BACKCHANNEL>>')) {
                        const clean = line.replace('<<MUSKY-BACKCHANNEL>>', '').trim();
                        updateLoadingMessage(clean);
                    }
                });

                return readChunk();
            });
        }

        return readChunk();
    })
    .catch(error => {
        console.error('Live loading failed:', error);
        hideLoadingSpinner();
    });
}
function updateLoadingMessage(text) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        const label = overlay.querySelector('.loading-text');
        if (label) label.textContent = text;
    }
}

window.onload = updateLastRefreshed;
</script>
</body>
</html>
