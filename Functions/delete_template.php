<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/MuskyBootstrap.php';
require_once __DIR__ . '/../web/check_access.php';
if (!musky_is_admin()) { echo json_encode(['error'=>'denied']); exit; }

$TEMPLATE_DIR = musky_template_dir() . '/';
$name = $_GET['name'] ?? '';

$path = realpath($TEMPLATE_DIR . $name);

if (!$path || !str_starts_with($path, $TEMPLATE_DIR)) {
    echo json_encode(['error'=>'invalid path']);
    exit;
}

unlink($path);
echo json_encode(['ok'=>true]);
