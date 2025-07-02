<?php
// =====================================================================
// fetch_loanerdata.php
// ---------------------------------------------------------------------
// Streams output from LoanerData.sh AND saves final stdout to file
// =====================================================================

require_once('../config.php');
require_once('loaner_constants.php');

header('Content-Type: text/plain');
header('X-Accel-Buffering: no');
@ob_implicit_flush(true);
@ob_end_flush();

$choice = $_GET['pool'] ?? '';

if (empty($choice) || !isset(LOANER_POOLS[$choice])) {
    echo "<<MUSKY-BACKCHANNEL>>(ERROR) Invalid or missing pool selection.\n";
    exit;
}

// Map short pool code to full loaner pool name
$LoanerPool2Search = LOANER_POOLS[$choice];

// Build correct shell command
$scriptPath = $LOCATION_SCRIPT_PATH . 'LoanerData.sh ' . escapeshellarg($LoanerPool2Search);

// Open script for reading
$process = popen($scriptPath, 'r');
if (is_resource($process)) {
    $outputBuffer = '';

    while (!feof($process)) {
        $line = fgets($process);
        if ($line !== false) {
            echo $line;
            flush();
            $outputBuffer .= $line;
        }
    }
    pclose($process);

    // Save the ENTIRE captured output into /tmp/loaner_devices.csv
    file_put_contents('/tmp/loaner_devices.csv', $outputBuffer);
} else {
    echo "<<MUSKY-BACKCHANNEL>>(ERROR) Could not open script.\n";
}
?>
