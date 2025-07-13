<?php
/**
 * MistExploreEverything.php
 *
 * Enhanced Mist API crawler.
 * - Uses .breadcrumb (expects 'token' and 'org_id')
 * - Automatically fetches site list
 * - For each site:
 *    → Hits core endpoints
 *    → Retrieves maps → then stats/maps/:map_id/clients
 *    → Retrieves devices → then stats/devices/:device_id/clients
 * - Dumps all data to raw_dumps/
 */

ini_set('memory_limit', '512M');
set_time_limit(0);
date_default_timezone_set('UTC');

$baseDir = __DIR__;
$breadcrumbFile = $baseDir . '/.breadcrumb';
$dumpDir = $baseDir . '/raw_dumps';
if (!is_dir($dumpDir)) mkdir($dumpDir, 0777, true);

function loadBreadcrumb($path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), "#")) continue;
        if (preg_match('/(\w+)\s*=\s*(.+)/', $line, $m)) {
            $out[trim($m[1])] = trim($m[2]);
        }
    }
    return $out;
}

function mistGET($url, $token, $retry = 3) {
    while ($retry-- > 0) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Token $token", "Accept: application/json"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 429) {
            sleep(2);
            continue;
        }
        return $code === 200 ? json_decode($resp, true) : null;
    }
    return null;
}

// Load secrets
$secrets = loadBreadcrumb($breadcrumbFile);
if (!$secrets['token'] || !$secrets['org_id']) exit("❌ .breadcrumb missing token or org_id
");
$token = $secrets['token'];
$orgId = $secrets['org_id'];

// Fetch sites
$sites = mistGET("https://api.mist.com/api/v1/orgs/$orgId/sites", $token);
if (!$sites) exit("❌ Could not fetch sites
");

$endpoints = [
    "stats/clients",
    "stats/devices",
    "clients/search",
    "clients/count",
    "insights/rogues",
    "insights/honeypot",
    "devices",
    "wlans"
];

foreach ($sites as $site) {
    $siteId = $site['id'];
    $siteName = $site['name'];
    echo "📍 Exploring: $siteName ($siteId)
";

    foreach ($endpoints as $ep) {
        $url = "https://api.mist.com/api/v1/sites/$siteId/$ep";
        $outFile = "$dumpDir/{$siteId}__" . str_replace(['/', ':'], '_', $ep) . ".json";
        $data = mistGET($url, $token);
        if ($data !== null) {
            file_put_contents($outFile, json_encode($data, JSON_PRETTY_PRINT));
            echo "   ✅ $ep → " . (is_array($data) ? count($data) : "1") . " records
";
        } else {
            echo "   ❌ $ep → Failed
";
        }
    }

    // Fetch maps → stats/maps/:map_id/clients
    $maps = mistGET("https://api.mist.com/api/v1/sites/$siteId/maps", $token);
    if (is_array($maps)) {
        foreach ($maps as $map) {
            $mapId = $map['id'];
            $mapEp = "stats/maps/$mapId/clients";
            $url = "https://api.mist.com/api/v1/sites/$siteId/$mapEp";
            $outFile = "$dumpDir/{$siteId}__stats_maps_{$mapId}_clients.json";
            $data = mistGET($url, $token);
            if ($data !== null) {
                file_put_contents($outFile, json_encode($data, JSON_PRETTY_PRINT));
                echo "   🗺️ $mapEp → " . count($data) . " records
";
            } else {
                echo "   ❌ $mapEp → Failed
";
            }
        }
    }

    // Fetch devices → stats/devices/:device_id/clients
    $devs = mistGET("https://api.mist.com/api/v1/sites/$siteId/devices", $token);
    if (is_array($devs)) {
        foreach ($devs as $dev) {
            $devId = $dev['id'];
            $devEp = "stats/devices/$devId/clients";
            $url = "https://api.mist.com/api/v1/sites/$siteId/$devEp";
            $outFile = "$dumpDir/{$siteId}__stats_devices_{$devId}_clients.json";
            $data = mistGET($url, $token);
            if ($data !== null) {
                file_put_contents($outFile, json_encode($data, JSON_PRETTY_PRINT));
                echo "   📡 $devEp → " . count($data) . " records
";
            } else {
                echo "   ❌ $devEp → Failed
";
            }
        }
    }
}
echo "✅ Done
";
?>
