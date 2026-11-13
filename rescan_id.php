<?php
require_once 'init.php';
require_once 'ai_ocr.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Head Admin: run the AI ID reader again on a pending applicant's stored ID and update the detected details.
function rescan_reply($ok, $message, $status = 200, array $extra = []) {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

if (!is_logged_in() || !is_role_in(['head_admin'])) rescan_reply(false, 'Only the Head Admin can re-scan IDs.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rescan_reply(false, 'Invalid request.', 405);
if (!csrf_valid($_POST['csrf'] ?? '')) rescan_reply(false, 'Your session expired. Reload the page and try again.', 419);
if (!ai_ocr_configured()) rescan_reply(false, 'AI ID scanning is not set up yet. Paste your Gemini API key into ai_config.php.', 503);

$id = (int)($_POST['id'] ?? 0);
$stmt = $conn->prepare("SELECT id, email, phone, address, barangay, position, role, verification_file FROM users WHERE id = ? AND status = 'pending' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) rescan_reply(false, 'This registration was already handled.', 409);

// Same path checks as view_id.php: only files inside uploads/ are ever read.
$root = realpath(__DIR__ . '/uploads');
$file = (string)($user['verification_file'] ?? '');
$full = $file !== '' ? realpath(__DIR__ . '/' . ltrim(str_replace('\\', '/', $file), '/')) : false;
if (!$root || !$full || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) rescan_reply(false, 'No ID image on file for this applicant.', 404);
$info = @getimagesize($full);
$mime = $info['mime'] ?? '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) rescan_reply(false, 'Only JPG, PNG or WEBP IDs can be scanned.', 415);
if (filesize($full) > 5 * 1024 * 1024) rescan_reply(false, 'This ID image is too large to scan (5 MB max).', 413);

session_write_close(); // do not hold the session lock during the API call
set_time_limit(90);
$result = ai_ocr_extract($full, $mime);
if (empty($result['ok'])) {
    audit_log('ID_RESCAN_FAILED', $user['email'], $result['error_class'] ?? 'Not a readable ID', 'Failed');
    // The Head Admin sees the provider's reason (e.g. "API key not valid") to fix the setup.
    rescan_reply(false, $result['message'] . (!empty($result['detail']) ? ' (' . $result['detail'] . ')' : ''), 200);
}

// Only overwrite what the AI actually read; keep the applicant's own contact number unless it is missing.
$f = $result['fields'];
$type = $f['staff_type'];
$role = $user['role'];
$position = $user['position'];
$barangay = (string)$user['barangay'];
if ($type === 'drrmc') {
    $role = 'official';
    $position = 'DRRMC Staff';
    $barangay = 'Caloocan';
} else {
    if ($type === 'barangay') {
        $role = 'user';
        $position = 'Barangay Staff';
    }
    // The barangay printed on the card is saved even when the card is not a staff ID (e.g. a Resident's Card).
    if ($f['barangay'] !== null) $barangay = (string)$f['barangay'];
}
$address = $f['address'] !== null ? $f['address'] : (string)$user['address'];
$phone = trim((string)$user['phone']) === '' && $f['phone'] !== null ? $f['phone'] : (string)$user['phone'];

$up = $conn->prepare("UPDATE users SET role = ?, position = ?, barangay = ?, address = ?, phone = ? WHERE id = ? AND status = 'pending'");
$up->bind_param('sssssi', $role, $position, $barangay, $address, $phone, $id);
$up->execute();
$up->close();

audit_log('ID_RESCANNED', $user['email'], 'Detected ' . ($type ?: 'unknown type') . '; barangay ' . ($f['barangay'] ?? 'not read') . '; confidence ' . $result['confidence'], 'Success');
$summary = 'ID re-scanned (' . $result['confidence'] . ' confidence).';
if ($result['evidence']) $summary .= ' AI saw: "' . $result['evidence'] . '".';
if ($result['note']) $summary .= ' ' . $result['note'];
rescan_reply(true, $summary);
