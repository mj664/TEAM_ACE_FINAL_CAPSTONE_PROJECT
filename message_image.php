<?php
// Serves a photo attached to a chat message. Only the barangay staff in that conversation and the DRRMC can open it.
require_once 'init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
// A deleted message's photo is no longer shown (the record stays in the database).
$stmt = $conn->prepare('SELECT user_id, image_path FROM contact_messages WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_office = is_role_in(['head_admin', 'official', 'admin']);
if (!$row || empty($row['image_path']) || (!$is_office && (int)$row['user_id'] !== (int)$_SESSION['user_id'])) {
    http_response_code(404);
    exit('Photo not found.');
}

$root = realpath(__DIR__ . '/uploads/messages');
$full = realpath(__DIR__ . '/' . ltrim(str_replace('\\', '/', (string)$row['image_path']), '/'));
if (!$root || !$full || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('Photo not found.');
}
$info = @getimagesize($full);
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!$info || !in_array($info['mime'], $allowed, true)) {
    http_response_code(415);
    exit('Unsupported file type.');
}

session_write_close();
header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($full);
