<?php
session_start();
require_once __DIR__ . '/../Functions/MuskyActivityLog.php';
musky_activity_log_logout([
    'page' => $_SERVER['HTTP_REFERER'] ?? '',
]);
session_unset();
session_destroy();
http_response_code(200);
