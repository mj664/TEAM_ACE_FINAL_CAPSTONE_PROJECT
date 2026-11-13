<?php
require_once 'init.php';
require_once 'weather_engine.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Barangay staff closed a weather restocking pop-up: don't show that alert to them again.
if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
$alert_id = (int)($_POST['id'] ?? 0);
if ($alert_id > 0) weather_mark_seen($alert_id, (int)$_SESSION['user_id']);
echo json_encode(['ok' => true]);
