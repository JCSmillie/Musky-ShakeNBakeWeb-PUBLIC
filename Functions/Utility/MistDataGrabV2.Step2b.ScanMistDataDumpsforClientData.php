<?php
/**
 * ScanMistDumpsForClientData.php
 * 
 * Scans raw_dumps folder for Mist API JSON files containing client-relevant identifiers.
 */

$rawDumpPath = __DIR__ . '/raw_dumps';
$interestingKeys = ['mac', 'hostname', 'user_name', 'device_id', 'ap_mac', 'bssid'];

$summary = [];

foreach (glob("$rawDumpPath/*.json") as $filePath) {
    $contents = file_get_contents($filePath);
    $json = json_decode($contents, true);
    if (!$json) continue;

    $hits = 0;
    $records = is_array($json) ? $json : ($json['data'] ?? []);
    
    if (!is_array($records)) continue;

    foreach ($records as $record) {
        foreach ($interestingKeys as $key) {
            if (isset($record[$key])) {
                $summary[$filePath][$key][] = $record[$key];
                $hits++;
            }
        }
    }

    if ($hits === 0) unset($summary[$filePath]);
}

// Print summary
foreach ($summary as $file => $foundKeys) {
    echo "📁 $file\n";
    foreach ($foundKeys as $key => $values) {
        $sample = array_unique(array_filter($values));
        $count = count($sample);
        echo "   - $key: $count unique entries\n";
    }
    echo "\n";
}
