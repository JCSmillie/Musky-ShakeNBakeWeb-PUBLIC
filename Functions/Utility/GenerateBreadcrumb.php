<?php
/**
 * GenerateBreadcrumb.php
 *
 * Prompts the user for Mist API Token and Org ID, then writes a properly formatted .breadcrumb file.
 */

$breadcrumbPath = __DIR__ . '/.breadcrumb';

echo "🔐 Enter Mist API Token: ";
$token = trim(fgets(STDIN));

echo "🏢 Enter Mist Org ID: ";
$org_id = trim(fgets(STDIN));

$content = <<<INI
; .breadcrumb
; This file stores reusable Mist dump variables in INI format

token = $token
org_id = $org_id

INI;

file_put_contents($breadcrumbPath, $content);
echo "✅ .breadcrumb written to: $breadcrumbPath\n";
