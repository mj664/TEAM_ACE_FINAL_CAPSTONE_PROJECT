<?php
require_once 'init.php';
require_once 'google_auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$identity = $_SESSION['google_identity'] ?? null;
if (!$identity || empty($identity['email'])) {
    echo json_encode(['status' => 'none', 'redirect' => 'login.php']);
    exit;
}

// The Google identity was verified in this session, so approval grants access straight away.
$target = google_resolve_login($identity, false);
if ($target === 'pending_approval.php') {
    echo json_encode(['status' => 'pending']);
} else {
    echo json_encode(['status' => 'ready', 'redirect' => $target]);
}
