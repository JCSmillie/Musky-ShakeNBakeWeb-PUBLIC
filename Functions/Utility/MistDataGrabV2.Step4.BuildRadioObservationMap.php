<?php
/**
 * MistDataGrabV2.Step4.BuildRadioObservationMap.php
 * Parses insights_honeypot files and builds radio_observation_map.json
 */

$rawDumpDir = __DIR__ . '/raw_dumps';
$outputFile = __DIR__ . '/radio_observation_map.json';
$radioObservationMap = [];

foreach (glob("$rawDumpDir/*_insights_honeypot.json") as $filename) {
    $json = json_decode(file_get_contents($filename), true);
    if (!isset($json['results']) || !is_array($json['results'])) continue;

    foreach ($json['results'] as $entry) {
        if (!isset($entry['bssid'])) continue;
        $bssid = strtolower($entry['bssid']);
        $radioObservationMap[$bssid] = [
            'ssid'        => $entry['ssid']        ?? null,
            'channel'     => $entry['channel']     ?? null,
            'ap_mac'      => $entry['ap_mac']      ?? null,
            'avg_rssi'    => $entry['avg_rssi']    ?? null,
            'times_heard' => $entry['times_heard'] ?? null,
            'band'        => $entry['band']        ?? null,
            'bandwidth'   => $entry['bandwidth']   ?? null,
            'delta_x'     => $entry['delta_x']     ?? null,
            'delta_y'     => $entry['delta_y']     ?? null,
        ];
    }
}

file_put_contents($outputFile, json_encode($radioObservationMap, JSON_PRETTY_PRINT));
echo "✅ Wrote radio_observation_map.json (" . count($radioObservationMap) . " entries)\n";
