<?php
require_once '../check_access.php';
// =====================================================================
// index.php
// ---------------------------------------------------------------------
// Main page for Loaner Device Manager
// =====================================================================

require_once('../config.php');
require_once('loaner_constants.php');
require_once('loaner_helpers.php');
require_once('loaner_utils.php');

$theme = $_COOKIE['theme'] ?? 'light';
$LoanerPool2Search = '';
$currentPoolLabel = '';
$choice = $_POST['pool'] ?? '';

// File where LoanerData.sh saves CSV output
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

<body class="<?= htmlspecialchars($theme) ?>-mode">
<div id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">Loading data... Please be patient.</div>
</div>
<div id="lastRefreshed">Last Refreshed: Not yet</div>
<button id="problemButton" onclick="submitProblem()">Problem?</button>
<img src="../mascot.png" id="mascot" class="corner-image" alt="Mascot">
<div class="content">
    <div class="main-container">
        <h1>
            <?php if (!empty($choice) && isset(LOANER_POOLS[$choice])): ?>
                Viewing: <?= htmlspecialchars(LOANER_POOLS[$choice]) ?> Devices
            <?php else: ?>
                Loaner Devices
            <?php endif; ?>
        </h1>

        <?php if (!empty($parsedData['rows'])): ?>
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
                            if (trim($cell) === 'NoInfo') {
                                echo '<td class="' . $class . '"></td>';
                            } else {
                                echo '<td class="' . $class . '">' . htmlspecialchars($cell) . '</td>';
                            }
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
    </div> <!-- End of main-container -->
    <div class="sidebar">

        <!-- Pool Selector Section -->
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

        <!-- Action Buttons Section -->
        <div class="sidebar-section" id="actionSection">
            <button id="multiWipe" onclick="multiWipeAction()" disabled class="action-button-red">Mass Wipe Selected</button>
            <button id="multiVerify" onclick="multiVerifyAction()" disabled class="action-button-orange">Verify Assignment</button>
            <button id="multiMessage" onclick="multiMessageAction()" disabled class="action-button-orange">Message User</button>
            <button type="button" onclick="reloadData()" class="action-button-red">Reload Data</button>
            <button id="toggleMoreDataBtn" onclick="toggleMoreData()">More Data</button>
            <button onclick="toggleDebug()">Toggle Debug</button>
        </div>

        <hr>

        <!-- Theme Selector Section -->
        <div class="sidebar-section" id="themeSection">
            <label for="themeSelect"><strong>Theme:</strong></label>
            <select id="themeSelect" onchange="changeTheme()" style="margin-top: 5px;">
                <option value="light" <?= ($theme == 'light') ? 'selected' : '' ?>>Light</option>
                <option value="dark" <?= ($theme == 'dark') ? 'selected' : '' ?>>Dark</option>
                <option value="musky" <?= ($theme == 'musky') ? 'selected' : '' ?>>Musky Mode</option>
            </select>
        </div>

    </div> <!-- End of sidebar -->
</div> <!-- End of content -->

<!-- Debug Output Pane -->
<div id="debugPane" style="display:none;">
    <h3>Debug Output</h3>
    <pre>Result:
<?= htmlspecialchars($csvContent ?? 'No data fetched yet.') ?></pre>
</div>
<script>
// =====================================================================
// JavaScript Functions for Loaner Device Manager Page
// =====================================================================

// Variables holding selected device info
let MultipleUDIDz = "";
let MultipleSerialz = "";
let MultipleTagz = "";

/**
 * Update selection tracking when checkboxes are toggled.
 */
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

    MultipleUDIDz = udids.join(',');
    MultipleSerialz = serials.join(',');
    MultipleTagz = tags.join(',');

    const hasSelection = udids.length > 0;
    document.getElementById('multiWipe').disabled = !hasSelection;
    document.getElementById('multiVerify').disabled = !hasSelection;
    document.getElementById('multiMessage').disabled = !hasSelection;
}

/**
 * Mass Wipe Action
 */
function multiWipeAction() {
    animateButton('multiWipe');
    if (MultipleUDIDz === '') {
        alert('Please select devices first.');
        return;
    }
    alert('Would execute wipe on: ' + MultipleUDIDz);
}

/**
 * Verify Assignment Action
 */
function multiVerifyAction() {
    animateButton('multiVerify');
    if (MultipleUDIDz === '') {
        alert('Please select devices first.');
        return;
    }
    alert('Would verify assignment for: ' + MultipleUDIDz);
}

/**
 * Message User Action
 */
function multiMessageAction() {
    animateButton('multiMessage');
    if (MultipleUDIDz === '') {
        alert('Please select devices first.');
        return;
    }
    alert('Would message users: ' + MultipleUDIDz);
}

/**
 * Select a Pool and start live loading
 */
function selectPool() {
    const poolValue = document.getElementById('poolSelect').value;
    if (poolValue === '') return;
    startLiveLoadingStatus('fetch_loanerdata.php?pool=' + encodeURIComponent(poolValue));
}

/**
 * Reload Data manually
 */
function reloadData() {
    showLoadingSpinner();
    setTimeout(() => {
        document.getElementById('poolForm').submit();
    }, 200);
}

/**
 * Show loading spinner
 */
function showLoadingSpinner() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'flex';
}

/**
 * Hide loading spinner
 */
function hideLoadingSpinner() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'none';
}

/**
 * Clear Pool Selection
 */
function clearPoolSelection() {
    const poolSelect = document.getElementById('poolSelect');
    if (poolSelect) {
        poolSelect.value = '';
        document.getElementById('poolForm').submit();
    }
}

/**
 * Toggle showing/hiding more data columns
 */
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

/**
 * Change Theme Handler
 */
function changeTheme() {
    const theme = document.getElementById('themeSelect').value;
    document.body.className = theme + '-mode';
    document.cookie = 'theme=' + theme + '; path=/';
}

/**
 * Start Live Loading Status Updates (MUSKY-BACKCHANNEL parsing)
 */
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
                    location.reload(); // <<< Force reload after done
                    return;
                }

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // save incomplete line

                lines.forEach(line => {
                    if (line.includes('<<MUSKY-BACKCHANNEL>>')) {
                        const cleanLine = line.replace('<<MUSKY-BACKCHANNEL>>', '').trim();
                        updateLoadingMessage(cleanLine);
                    }
                });

                return readChunk();
            });
        }

        return readChunk();
    })
    .catch(error => {
        console.error('Live loading status error:', error);
        hideLoadingSpinner();
    });
}

/**
 * Update Loading Message from MUSKY-BACKCHANNEL
 */
function updateLoadingMessage(text) {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.querySelector('.loading-text').textContent = text;
    }
}

/**
 * Toggle Debug Output
 */
function toggleDebug() {
    const debugPane = document.getElementById('debugPane');
    debugPane.style.display = (debugPane.style.display === 'none' || debugPane.style.display === '') ? 'block' : 'none';
}

/**
 * Animate Button Pulse Effect
 */
function animateButton(btnId) {
    const btn = document.getElementById(btnId);
    if (btn) {
        btn.classList.add('button-clicked');
        setTimeout(() => {
            btn.classList.remove('button-clicked');
        }, 400);
    }
}

/**
 * Submit problem report with screenshot
 */
function submitProblem() {
    const feedbackText = prompt('Please describe the problem you are reporting:');
    if (feedbackText === null || feedbackText.trim() === '') {
        alert('Problem report canceled.');
        return;
    }

    const mascot = document.getElementById('mascot');
    mascot.classList.add('bounce');

    html2canvas(document.body).then(canvas => {
        const screenshotData = canvas.toDataURL('image/png');
        const form = new FormData();
        form.append('screenshot', screenshotData);
        form.append('feedback', feedbackText);
        form.append('serial', 'UNKNOWN_SERIAL');
        form.append('assettag', 'UNKNOWN_ASSETTAG');
        form.append('loanerpool', '<?= htmlspecialchars($currentPoolLabel ?? "Unknown Pool") ?>');

        fetch('../submit_problem.php', {
            method: 'POST',
            body: form
        })
        .then(response => {
            mascot.classList.remove('bounce');
            alert(response.ok ? 'Problem submitted successfully.' : 'Problem submission failed.');
        })
        .catch(() => {
            mascot.classList.remove('bounce');
            alert('Error submitting problem.');
        });
    });
}

/**
 * Update Last Refreshed Time
 */
function updateLastRefreshed() {
    const refreshed = document.getElementById('lastRefreshed');
    if (refreshed) {
        refreshed.textContent = 'Last Refreshed: ' + new Date().toLocaleTimeString();
    }
}

// Initialize
window.onload = updateLastRefreshed;
</script>
</body>
</html>
