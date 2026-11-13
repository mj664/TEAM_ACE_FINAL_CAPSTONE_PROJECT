<?php
require_once 'init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// The logged-in person's current profile picture and name, so an open map can update its "you are here" pin
// as soon as the picture is changed (see assets/south_caloocan_map.js).
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['name' => '', 'photo' => '']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
session_write_close();
$stmt = $conn->prepare('SELECT fullname, profile_logo FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
echo json_encode(['name' => (string)($row['fullname'] ?? ''), 'photo' => (string)($row['profile_logo'] ?? '')], JSON_UNESCAPED_SLASHES);
