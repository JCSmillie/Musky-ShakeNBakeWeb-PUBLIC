<?php
// =====================================================================
// loaner_utils.php
// ---------------------------------------------------------------------
// Utility functions used for CSV parsing and shell output processing
// for the Loaner Device Manager.
// =====================================================================

/**
 * Parse CSV output from a shell command that contains a header line
 * followed by device records.
 *
 * @param string $rawOutput Full raw output from shell script
 * @return array Returns ['headers' => [...], 'rows' => [...]]
 */
function parseCSVFromShellOutput($rawOutput) {
    if (strpos($rawOutput, '====================') === false) {
        return [];
    }
    $parts = explode('====================', $rawOutput, 2);
    $csvData = trim($parts[1]);
    $lines = array_filter(array_map('trim', explode("\n", $csvData)));
    
    if (count($lines) < 2) {
        return [];
    }

    $headers = str_getcsv(array_shift($lines)); // First line is headers
    $dataRows = array_map('str_getcsv', $lines); // Remaining lines are data
    return ['headers' => $headers, 'rows' => $dataRows];
}
?>

