<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/MuskyBootstrap.php';
require_once __DIR__ . '/../web/check_access.php';

if (!musky_is_admin()) { http_response_code(403); exit; }

$name = $_GET['name'] ?? '';
$TEMPLATE_DIR = musky_template_dir() . '/';

$path = realpath($TEMPLATE_DIR . $name);
if (!$path || !str_starts_with($path, $TEMPLATE_DIR)) {
    echo json_encode(['error'=>'Invalid template']);
    exit;
}

echo file_get_contents($path);
