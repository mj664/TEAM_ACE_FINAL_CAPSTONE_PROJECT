<?php
require_once 'init.php';
require_login();
require_role(['head_admin']); // ID cards are sensitive: Head Admin only

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT verification_file FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$file = (string)($stmt->get_result()->fetch_assoc()['verification_file'] ?? '');
$stmt->close();

$root = realpath(__DIR__ . '/uploads');
$full = $file !== '' ? realpath(__DIR__ . '/' . ltrim(str_replace('\\', '/', $file), '/')) : false;
if (!$root || !$full || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('ID not found.');
}

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
$mime = function_exists('mime_content_type') ? mime_content_type($full) : '';
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Unsupported file type.');
}

audit_log('ID_VIEWED', 'user #' . $id);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($full);
