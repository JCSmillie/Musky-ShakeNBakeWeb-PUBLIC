<?php
/**
 * MistDataGrab.Step2.BuildBSIDMapfromDumps.php
 *
 * Step 2 of the Mist dump process. This script processes the JSON files dumped by Step 1,
 * expanding BSSID ranges and building comprehensive mappings:
 *
 * - MAC (AP) → AP Name
 * - BSSID → AP Name (including virtual MACs from Mist BSSID ranges)
 * - BSSID → Channel (from stats_clients_*.json)
 * - BSSID → AP IP (when available)
 */

function naturalize_mac($mac) {
    return strtoupper(preg_replace('/(..)(?=.)/', '$1:', str_replace(['-', ':'], '', $mac)));
}

// Load files
$orgInventory = json_decode(@file_get_contents("org_inventory.json"), true);
$statsClients = glob("stats_clients_*.json");
$statsDevices = glob("stats_devices_*.json");

// Build MAC → Name map
$macToName = [];
if (is_array($orgInventory)) {
    foreach ($orgInventory as $device) {
        if (isset($device['mac']) && isset($device['name'])) {
            $macToName[naturalize_mac($device['mac'])] = $device['name'];
        }
    }
}

// BSSID maps
$bssidToName = [];
$bssidToChannel = [];
$bssidToIP = []; // placeholder if we ever get ap_ip in future

// Step 1: From stats_devices → get base radios and expand BSSID ranges
foreach ($statsDevices as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) continue;

    foreach ($data as $device) {
        $apName = isset($device['name']) ? $device['name'] : null;
        $radios = $device['radios'] ?? [];
        foreach ($radios as $radio) {
            if (!isset($radio['mac']) || !isset($radio['bssid'])) continue;

            $baseMac = naturalize_mac($radio['bssid']);
            $apMac = naturalize_mac($radio['mac']);

            if (preg_match('/^(.+)([0-9A-F]{2})-([0-9A-F]{2})$/i', $baseMac, $m)) {
                $prefix = $m[1];
                $start = hexdec($m[2]);
                $end = hexdec($m[3]);
                for ($i = $start; $i <= $end; $i++) {
                    $full = $prefix . sprintf("%02X", $i);
                    $bssidToName[$full] = $apName ?? $macToName[$apMac] ?? "???";
                }
            } else {
                $bssidToName[$baseMac] = $apName ?? $macToName[$apMac] ?? "???";
            }
        }
    }
}

// Step 2: From stats_clients → get BSSID, ap_mac, channel
foreach ($statsClients as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) continue;

    foreach ($data as $client) {
        if (!isset($client['bssid'])) continue;
        $bssid = naturalize_mac($client['bssid']);
        $apMac = isset($client['ap_mac']) ? naturalize_mac($client['ap_mac']) : null;
        $channel = $client['channel'] ?? null;

        if ($channel) {
            $bssidToChannel[$bssid] = $channel;
        }

        if (!isset($bssidToName[$bssid])) {
            $bssidToName[$bssid] = $macToName[$apMac] ?? "???";
        }
    }
}

// Output files
file_put_contents("bssid_to_apname_map.json", json_encode($bssidToName, JSON_PRETTY_PRINT));
file_put_contents("bssid_to_channel_map.json", json_encode($bssidToChannel, JSON_PRETTY_PRINT));
file_put_contents("mac_to_apname_map.json", json_encode($macToName, JSON_PRETTY_PRINT));

// Tally counts
$macToNameCount = count($macToName);
$bssidToNameCount = count($bssidToName);
$bssidToChannelCount = count($bssidToChannel);

// Display summary
echo "✅ Build Summary:\n";
echo "- MAC → AP Name: {$macToNameCount}\n";
echo "- BSSID → AP Name: {$bssidToNameCount}\n";
echo "- BSSID → AP IP: " . count($bssidToIP) . "\n";
echo "- BSSID → Channel: {$bssidToChannelCount}\n";

// Save to .breadcrumb
file_put_contents(".breadcrumb", <<<TXT
# .breadcrumb
# This file stores reusable Mist dump variables

# Summary of latest build:
# - MAC → AP Name: {$macToNameCount}
# - BSSID → AP Name: {$bssidToNameCount}
# - BSSID → Channel: {$bssidToChannelCount}

# Files:
# - bssid_to_apname_map.json
# - bssid_to_channel_map.json
# - mac_to_apname_map.json
# - stats_devices_*.json (input)
# - stats_clients_*.json (input)
TXT);

