<?php
/**
 * MistDataDump.php — Full Mist Data Exporter
 *
 * This script queries every useful endpoint Mist exposes per site.
 * It dumps all raw data for further analysis.
 *
 * Data collected:
 * - Org sites list
 * - Org-wide inventory
 * - Per-site:
 *   - stats/devices
 *   - devices
 *   - stats/clients
 *   - stats/aps
 *   - apmetrics (if supported)
 *
 * Outputs:
 * - .breadcrumb           ← token + org ID
 * - site_list.json
 * - org_inventory.json
 * - stats_*.json per site
 */

$is_cli = php_sapi_name() === 'cli';
$token = $_GET['token'] ?? null;
$org_id = $_GET['org_id'] ?? null;

// Load from .breadcrumb if needed
if ((!$token || !$org_id) && file_exists(".breadcrumb")) {
    foreach (file(".breadcrumb") as $line) {
        if (preg_match('/^TOKEN="(.+)"$/', $line, $m)) $token = $token ?? $m[1];
        if (preg_match('/^ORG_ID="(.+)"$/', $line, $m)) $org_id = $org_id ?? $m[1];
    }
}

if (!$token && $is_cli) $token = readline("Enter Mist API Token: ");
if (!$org_id && $is_cli) $org_id = readline("Enter Mist Org ID: ");
if (!$token || !$org_id) {
    echo "❌ Missing token or org ID.\n";
    exit;
}

function api_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Token $token",
        "Accept: application/json"
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

// Fetch site list
[$site_code, $sites, $raw_sites] = api_get("https://api.mist.com/api/v1/orgs/$org_id/sites", $token);
file_put_contents("site_list.json", $raw_sites);
if ($site_code !== 200 || !is_array($sites)) {
    echo "❌ Failed to fetch sites (HTTP $site_code)\n";
    exit;
}
echo "✅ site_list.json saved (" . count($sites) . " sites)\n";

// Fetch inventory
[$inv_code, $_, $raw_inv] = api_get("https://api.mist.com/api/v1/orgs/$org_id/inventory", $token);
file_put_contents("org_inventory.json", $raw_inv);
echo "✅ org_inventory.json saved\n";

// Define all per-site endpoints we want to query
$site_endpoints = [
    "stats/devices",
    "devices",
    "stats/clients",
    "stats/aps",
    "apmetrics"
];

// Query all per-site endpoints
foreach ($sites as $site) {
    $site_id = $site['id'];
    $site_name = $site['name'] ?? 'Unnamed Site';
    echo "🔍 $site_name ($site_id)\n";

    foreach ($site_endpoints as $suffix) {
        $url = "https://api.mist.com/api/v1/sites/$site_id/$suffix";
        [$code, $_, $raw] = api_get($url, $token);
        $fname = str_replace('/', '_', $suffix) . "_$site_id.json";
        file_put_contents($fname, $raw);
        echo "   ↳ $suffix → $fname (HTTP $code)\n";
    }
}

// Write breadcrumb for reuse
file_put_contents(".breadcrumb", <<<TXT
# Mist API token for reuse
TOKEN="$token"

# Org ID for all site-level exports
ORG_ID="$org_id"
TXT);

echo "📘 .breadcrumb updated\n";
echo "✅ Full Mist data export complete.\n";
?>

