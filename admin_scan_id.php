<?php
require_once 'init.php';
require_once 'ai_ocr.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Head Admin "Create Account": read an uploaded staff ID with AI and return the details to pre-fill the form.
function scan_reply($ok, $message, $status = 200, array $extra = []) {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

if (!is_logged_in() || !is_role_in(['head_admin'])) scan_reply(false, 'Only the Head Admin can scan IDs here.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') scan_reply(false, 'Invalid request.', 405);
if (!csrf_valid($_POST['csrf'] ?? '')) scan_reply(false, 'Your session expired. Reload the page and try again.', 419);
if (!ai_ocr_configured()) scan_reply(false, 'AI ID scanning is not set up yet. Paste your Gemini API key into ai_config.php.', 503);

$file = $_FILES['id_image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) scan_reply(false, 'Choose an ID image to scan.', 400);
if ($file['size'] > 5 * 1024 * 1024) scan_reply(false, 'This image is too large to scan (5 MB max). Fill in the details by hand.', 413);
$info = @getimagesize($file['tmp_name']);
$mime = $info['mime'] ?? '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) scan_reply(false, 'The ID must be a JPG, PNG or WEBP image.', 415);

session_write_close(); // do not hold the session lock during the API call
set_time_limit(90);
$result = ai_ocr_extract($file['tmp_name'], $mime);
if (empty($result['ok'])) {
    audit_log('ID_SCAN_FAILED', 'Create Account', $result['error_class'] ?? 'Not a readable ID', 'Failed');
    scan_reply(false, $result['message'] . (!empty($result['detail']) ? ' (' . $result['detail'] . ')' : ''), 200);
}
audit_log('ID_SCANNED', 'Create Account', 'Detected ' . ($result['fields']['staff_type'] ?: 'unknown type') . '; confidence ' . $result['confidence']);
scan_reply(true, 'ID scanned.', 200, [
    'fields' => $result['fields'],
    'note' => $result['note'],
    'evidence' => $result['evidence'],
    'confidence' => $result['confidence'],
]);
