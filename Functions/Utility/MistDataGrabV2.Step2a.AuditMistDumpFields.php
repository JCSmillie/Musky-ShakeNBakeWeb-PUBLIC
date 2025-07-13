<?php
/**
 * AuditMistDumpFields.php
 * Scans all JSON files in raw_dumps/ and reports which fields are present.
 */

$folder = __DIR__ . '/raw_dumps';
$interestingFields = ['mac', 'bssid', 'hostname', 'ap_mac', 'last_seen', 'ssid', 'device_id', 'map_id', 'manufacturer', 'os'];
$results = [];

foreach (glob("$folder/*.json") as $filePath) {
    $fileName = basename($filePath);
    $json = @file_get_contents($filePath);
    if (!$json) continue;

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) continue;

    $items = [];
    if (isset($data[0])) {
        $items = $data;
    } elseif (isset($data['data']) && is_array($data['data'])) {
        $items = $data['data'];
    }

    foreach ($items as $record) {
        if (!is_array($record)) continue;
        foreach ($interestingFields as $field) {
            if (isset($record[$field])) {
                $results[$fileName][$field][] = $record[$field];
            }
        }
    }
}

// Output Summary
foreach ($results as $file => $fields) {
    echo "📂 $file\n";
    foreach ($fields as $field => $values) {
        $sample = array_slice(array_unique($values), 0, 3);
        echo "   🔹 $field: " . count($values) . " values | Sample: " . implode(', ', $sample) . "\n";
    }
    echo "\n";
}
