<?php
require_once 'init.php';
require_once 'ai_ocr.php';
require_once 'ai_face.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function ocr_reply($ok, $message, $status = 200, array $extra = []) {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

// Only applicants who completed Google sign-in and are not registered yet may scan an ID.
$identity = $_SESSION['google_identity'] ?? null;
if (!$identity || empty($identity['email'])) ocr_reply(false, 'Please sign in with Google first.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ocr_reply(false, 'Invalid request.', 405);
if (!csrf_valid($_POST['csrf'] ?? '')) ocr_reply(false, 'Your session expired. Reload the page and try again.', 419);
if (!ai_ocr_configured()) ocr_reply(false, 'ID scanning is not available. Please enter your details by hand.', 503);

$exists = $conn->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
$exists->bind_param('s', $identity['email']);
$exists->execute();
if ($exists->get_result()->num_rows) ocr_reply(false, 'This account is already registered.', 409);
$exists->close();

$file = $_FILES['id_image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) ocr_reply(false, 'Choose an ID image to scan.', 400);
if ($file['size'] > 5 * 1024 * 1024) ocr_reply(false, 'This image is too large to scan (5 MB max). Please enter your details by hand.', 413);
$info = @getimagesize($file['tmp_name']);
$mime = $info['mime'] ?? '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) ocr_reply(false, 'The ID must be a JPG, PNG or WEBP image.', 415);

// Cost guard: each scan is a paid API call.
$_SESSION['ocr_scans'] = (int)($_SESSION['ocr_scans'] ?? 0) + 1;
if ($_SESSION['ocr_scans'] > (int)ai_config()['max_scans_per_session']) {
    ocr_reply(false, 'Scan limit reached for this session. Please enter your details by hand.', 429);
}

$file_hash = hash_file('sha256', $file['tmp_name']);
session_write_close(); // do not hold the session lock during the API call
set_time_limit(90);
$result = ai_ocr_extract($file['tmp_name'], $mime);

// Registration rules checked right away, so the applicant knows before submitting:
// 1) one staff account per barangay, 2) the face on the ID must match the Google account photo.
$block = null; $face = null;
if (!empty($result['ok'])) {
    $fb = $result['fields']['barangay'] ?? null;
    if (($result['fields']['staff_type'] ?? '') !== 'drrmc' && $fb !== null && ($holder = barangay_account_taken($fb))) {
        $block = barangay_taken_message($fb, $holder) . ' If you are replacing the current staff member, ask the Head Admin to deactivate the old account first.';
    } else {
        $face = ai_face_check($file['tmp_name'], $mime, $identity['picture'] ?? '');
        if ($face['blocked']) $block = $face['message'];
        audit_log('ID_FACE_CHECK', $identity['email'], 'Face check: ' . $face['verdict'] . ($face['reason'] !== '' ? ' (' . $face['reason'] . ')' : ''), $face['blocked'] ? 'Failed' : 'Success', null, $identity['email']);
    }
}

// Keep the trusted result on the server, tied to this exact file, so registration never relies on browser-supplied values.
if (!empty($result['ok'])) {
    session_start();
    $_SESSION['ocr_result'] = ['hash' => $file_hash, 'fields' => $result['fields'], 'confidence' => $result['confidence'], 'face' => $face];
    session_write_close();
}
if ($block !== null) {
    audit_log('REGISTRATION_BLOCKED', $identity['email'], $block, 'Failed', null, $identity['email']);
    ocr_reply(false, $block, 200, ['blocked' => true]);
}

if (empty($result['ok'])) {
    audit_log('ID_SCAN_FAILED', $identity['email'], $result['error_class'] ?? 'Not a readable ID', 'Failed', null, $identity['email']);
    ocr_reply(false, $result['message'], 200);
}
audit_log('ID_SCANNED', $identity['email'], 'Detected ' . ($result['fields']['staff_type'] ?: 'unknown type') . '; confidence ' . $result['confidence'], 'Success', null, $identity['email']);
ocr_reply(true, 'ID scanned.', 200, ['fields' => $result['fields'], 'note' => $result['note'], 'face' => $face ? ['verdict' => $face['verdict'], 'message' => $face['message']] : null]);
