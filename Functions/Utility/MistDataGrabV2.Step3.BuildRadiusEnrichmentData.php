<?php
/**
 * MistDataGrabV2.Step3.BuildRadiusEnrichmentData.php
 *
 * Uses raw_dumps to build lookup maps for RadiusUserIDLookup.php:
 *   - mac_to_apname_map.json
 *   - bssid_to_apname_map.json
 *   - bssid_to_channel_map.json
 *   - client_lastseen_map.json
 *
 * Requires: .breadcrumb (for directory context only)
 */

$baseDir = __DIR__;
$dumpDir = "$baseDir/raw_dumps";
$macToAp = [];
$bssidToAp = [];
$bssidToChannel = [];
$clientLastSeen = [];

// Load all stats_devices_*.json files for AP name and channel mapping
foreach (glob("$dumpDir/*__stats_devices.json") as $filename) {
    $data = json_decode(file_get_contents($filename), true);
    if (!is_array($data)) continue;

    foreach ($data as $device) {
        $apName = $device['name'] ?? null;
        $mac = $device['mac'] ?? null;
        $radios = $device['radios'] ?? [];

        if ($mac && $apName) {
            $macToAp[$mac] = $apName;
        }

        foreach ($radios as $radio) {
            foreach ($radio['interfaces'] ?? [] as $iface) {
                if (isset($iface['bssid']) && isset($iface['channel'])) {
                    $bssidToAp[$iface['bssid']] = $apName;
                    $bssidToChannel[$iface['bssid']] = $iface['channel'];
                }
            }
        }
    }
}

// Load all *_clients.json files for mac to AP name and BSSID mapping
foreach (glob("$dumpDir/*_clients.json") as $filename) {
    $data = json_decode(file_get_contents($filename), true);
    if (!is_array($data)) continue;

    foreach ($data as $client) {
        $clientMac = $client['mac'] ?? null;
        $apMac = $client['ap_mac'] ?? null;
        $bssid = $client['bssid'] ?? null;
        $ts = $client['last_seen'] ?? null;

        if ($clientMac && $apMac && isset($macToAp[$apMac])) {
            $macToAp[$clientMac] = $macToAp[$apMac];
        }

        if ($bssid && $apMac && isset($macToAp[$apMac])) {
            $bssidToAp[$bssid] = $macToAp[$apMac];
        }

        if ($clientMac && $ts) {
            if (!isset($clientLastSeen[$clientMac]) || $clientLastSeen[$clientMac] < $ts) {
                $clientLastSeen[$clientMac] = $ts;
            }
        }
    }
}

// Save maps
file_put_contents("$baseDir/mac_to_apname_map.json", json_encode($macToAp, JSON_PRETTY_PRINT));
echo "✅ Wrote mac_to_apname_map.json (" . count($macToAp) . " entries)\n";

file_put_contents("$baseDir/bssid_to_apname_map.json", json_encode($bssidToAp, JSON_PRETTY_PRINT));
echo "✅ Wrote bssid_to_apname_map.json (" . count($bssidToAp) . " entries)\n";

file_put_contents("$baseDir/bssid_to_channel_map.json", json_encode($bssidToChannel, JSON_PRETTY_PRINT));
echo "✅ Wrote bssid_to_channel_map.json (" . count($bssidToChannel) . " entries)\n";

file_put_contents("$baseDir/client_lastseen_map.json", json_encode($clientLastSeen, JSON_PRETTY_PRINT));
echo "✅ Wrote client_lastseen_map.json (" . count($clientLastSeen) . " entries)\n";
