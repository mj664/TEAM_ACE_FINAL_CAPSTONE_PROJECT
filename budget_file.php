<?php
// Serves a stored expense file (Excel breakdown or receipt) to people allowed to see it, after checking its fingerprint.
require_once 'init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$which = ($_GET['f'] ?? '') === 'excel' ? 'excel' : 'receipt';

$stmt = $conn->prepare('SELECT * FROM tbl_procurement_upload WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$up = $stmt->get_result()->fetch_assoc();
$stmt->close();

$allowed = false;
if ($up) {
    if (is_role_in(['head_admin', 'admin'])) $allowed = true;
    else {
        $stmt = $conn->prepare('SELECT barangay FROM users WHERE id = ?');
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $mine = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $allowed = $mine && (string)$mine['barangay'] === (string)$up['barangay'];
    }
}
if (!$up || !$allowed) { http_response_code(404); exit('File not found.'); }

$rel  = $which === 'excel' ? $up['excel_path'] : $up['receipt_path'];
$hash = $which === 'excel' ? $up['excel_sha256'] : $up['receipt_sha256'];
$name = $which === 'excel' ? $up['excel_original_name'] : $up['receipt_original_name'];
$excel_mime = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
$mime = $which === 'excel' ? $excel_mime : $up['receipt_mime'];
$path = __DIR__ . '/storage/' . $rel;

if (!is_file($path)) { http_response_code(404); exit('File not found.'); }
if (!hash_equals($hash, hash_file('sha256', $path))) {
    // The stored file no longer matches the fingerprint taken at upload: it was replaced or altered.
    audit_log('EXPENSE_FILE_TAMPERED', 'packet #' . $id, $which . ' file fingerprint mismatch', 'Failed');
    http_response_code(409);
    exit('Integrity check failed: this file does not match the fingerprint recorded when it was uploaded. The incident has been logged.');
}

audit_log('EXPENSE_FILE_VIEWED', 'packet #' . $id, $which . ' file opened');
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
$inline = $which === 'receipt';
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode(basename($name)) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
