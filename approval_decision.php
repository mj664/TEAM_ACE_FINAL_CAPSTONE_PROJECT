<?php
require_once 'init.php';
header('Content-Type: application/json');

function decision_reply($ok, $message, $status = 200) {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if (!is_logged_in() || !is_role_in(['head_admin'])) decision_reply(false, 'Only the Head Admin can decide registrations.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') decision_reply(false, 'Invalid request.', 405);
if (!csrf_valid($_POST['csrf'] ?? '')) decision_reply(false, 'Your session expired. Reload the page and try again.', 419);

$id = (int)($_POST['id'] ?? 0);
$decision = $_POST['decision'] ?? '';
if ($id < 1 || !in_array($decision, ['approve', 'reject'], true)) decision_reply(false, 'Invalid request.', 400);

$stmt = $conn->prepare("SELECT id, email, fullname, position, barangay FROM users WHERE id = ? AND status = 'pending' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) decision_reply(false, 'This registration was already handled.', 409);

if ($decision === 'approve') {
    $role = $_POST['role'] ?? '';
    // A role read from the ID (saved as the position by registration / re-scan) is fixed and wins over the browser's choice.
    if ($user['position'] === 'Barangay Staff') $role = 'user';
    elseif ($user['position'] === 'DRRMC Staff') $role = 'admin';
    if (!in_array($role, ['user', 'admin'], true)) decision_reply(false, 'Choose a role to assign.', 400);
    // Barangay staff must belong to a barangay (AI-detected from the ID, or assigned here); DRRMC admins are city-level.
    $barangay = trim((string)($_POST['barangay'] ?? ''));
    // A barangay read from the ID is fixed: it always wins over what the browser sends.
    $from_id = trim((string)$user['barangay']);
    if ($role === 'user' && ctype_digit($from_id) && (int)$from_id >= 1 && (int)$from_id <= 188) $barangay = $from_id;
    if ($role === 'user') {
        if ($barangay === '' || !ctype_digit($barangay) || (int)$barangay < 1 || (int)$barangay > 188) decision_reply(false, 'Choose the barangay for this staff member before approving.', 400);
    } else {
        $barangay = 'Caloocan';
    }
    if ($role === 'user' && ($holder = barangay_account_taken($barangay, $id, false))) {
        audit_log('ACCOUNT_APPROVAL_BLOCKED', $user['email'], 'Barangay ' . $barangay . ' already has an approved account (#' . $holder['id'] . ')', 'Failed');
        decision_reply(false, barangay_taken_message($barangay, $holder) . ' Deactivate ' . $holder['fullname'] . ' first, or reject this registration.', 409);
    }
    if (email_account_taken($user['email'], $id)) {
        audit_log('ACCOUNT_APPROVAL_BLOCKED', $user['email'], 'This Gmail already has an active account', 'Failed');
        decision_reply(false, 'This Gmail already has an active account. Only one account is allowed per Gmail.', 409);
    }
    $update = $conn->prepare("UPDATE users SET status = 'approved', role = ?, barangay = ? WHERE id = ? AND status = 'pending'");
    $update->bind_param('ssi', $role, $barangay, $id);
    $update->execute();
    if ($update->affected_rows !== 1) decision_reply(false, 'This registration was already handled.', 409);
    $update->close();
    audit_log('ACCOUNT_APPROVED', $user['email'], 'Role assigned: ' . $role . '; barangay ' . $barangay);
    mail_account_status('approved', ['role' => $role, 'barangay' => $barangay] + $user);
    decision_reply(true, 'Approved as ' . ($role === 'admin' ? 'DRRMC Admin' : 'Barangay Staff') . '.');
}

$reason = trim((string)($_POST['reason'] ?? ''));
$update = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ? AND status = 'pending'");
$update->bind_param('i', $id);
$update->execute();
if ($update->affected_rows !== 1) decision_reply(false, 'This registration was already handled.', 409);
$update->close();
audit_log('ACCOUNT_REJECTED', $user['email'], $reason !== '' ? 'Reason: ' . $reason : 'No reason given');
mail_account_status('rejected', $user, $reason);
decision_reply(true, 'Registration rejected.');
