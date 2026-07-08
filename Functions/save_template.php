<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/MuskyBootstrap.php';
require_once __DIR__ . '/../web/check_access.php';
if (!musky_is_admin()) { echo json_encode(['error'=>'denied']); exit; }

$TEMPLATE_DIR = musky_template_dir();

$raw = json_decode(file_get_contents("php://input"), true);
$filename = preg_replace('/[^a-zA-Z0-9_\-]/','',$raw['filename'] ?? '');
$data = $raw['data'] ?? [];

if (!$filename) { echo json_encode(['error'=>'Invalid filename']); exit; }

$path = realpath($TEMPLATE_DIR) . "/" . $filename . ".json";

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
echo json_encode(['ok'=>true]);
