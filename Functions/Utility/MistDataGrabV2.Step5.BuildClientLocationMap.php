<?php
$dir = __DIR__ . '/raw_dumps';
$outFile = __DIR__ . '/client_location_map.json';
$map = [];

foreach (glob("$dir/*_stats_clients.json") as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) continue;

    foreach ($data as $client) {
        if (!isset($client['mac'])) continue;

        $mac = $client['mac'];
        $map[$mac] = [
            'bssid' => $client['bssid'] ?? null,
            'ssid' => $client['ssid'] ?? null,
            'map_id' => $client['map_id'] ?? null,
            'x' => $client['x'] ?? null,
            'y' => $client['y'] ?? null,
            'last_seen' => $client['last_seen'] ?? null
        ];
    }
}

file_put_contents($outFile, json_encode($map, JSON_PRETTY_PRINT));
echo "✅ Wrote client_location_map.json (" . count($map) . " entries)\n";
