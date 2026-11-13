<?php
require_once 'init.php';
require_login();
require_role(['head_admin']);

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

// Permanent delete (Head Admin only). Only accounts with no saved records can be deleted, so a barangay's
// equipment reports, requests and transaction history are never erased; those accounts are deactivated instead.
if ($action === 'delete') {
    $back_tab = in_array($_POST['tab'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_POST['tab'] : 'rejected';
    if (!csrf_valid($_POST['csrf'] ?? '') || $id < 1 || $id === (int)$_SESSION['user_id']) { header('Location: admin_approvals.php?tab=' . $back_tab); exit; }
    $q = $conn->prepare("SELECT id, email, fullname, role, verification_file, (SELECT COUNT(*) FROM equipment_reports x WHERE x.user_id = users.id) + (SELECT COUNT(*) FROM item_transactions x WHERE x.processed_by = users.id) + (SELECT COUNT(*) FROM resource_requests x WHERE x.user_id = users.id) + (SELECT COUNT(*) FROM training_requests x WHERE x.user_id = users.id) + (SELECT COUNT(*) FROM training_drills x WHERE x.created_by = users.id) + (SELECT COUNT(*) FROM advertisements x WHERE x.created_by = users.id) AS records FROM users WHERE id = ? LIMIT 1");
    $q->bind_param('i', $id); $q->execute(); $acc = $q->get_result()->fetch_assoc(); $q->close();
    // Head Admin accounts are protected: they can never be deleted.
    if (!$acc || $acc['role'] === 'head_admin') { header('Location: admin_approvals.php?tab=' . $back_tab); exit; }
    if ((int)$acc['records'] > 0) {
        audit_log('ACCOUNT_DELETE_BLOCKED', (string)$acc['email'], (int)$acc['records'] . ' saved record(s); deactivate instead', 'Failed');
        header('Location: admin_approvals.php?tab=' . $back_tab . '&msg=delete_blocked'); exit;
    }
    try {
        $d = $conn->prepare('DELETE FROM users WHERE id = ? AND role <> \'head_admin\'');
        $d->bind_param('i', $id); $d->execute(); $gone = $d->affected_rows === 1; $d->close();
    } catch (mysqli_sql_exception $ex) { $gone = false; }
    if (!$gone) { header('Location: admin_approvals.php?tab=' . $back_tab . '&msg=delete_blocked'); exit; }
    $idf = (string)$acc['verification_file'];
    if ($idf !== '' && strpos($idf, 'uploads/ids/') === 0 && strpos($idf, '..') === false && is_file(__DIR__ . '/' . $idf)) @unlink(__DIR__ . '/' . $idf);
    audit_log('ACCOUNT_DELETED', (string)$acc['email'], 'Deleted permanently: ' . $acc['fullname'] . ' (role ' . $acc['role'] . ')');
    header('Location: admin_approvals.php?tab=' . $back_tab . '&msg=deleted'); exit;
}
if ($id > 0 && in_array($action, ['deactivate', 'reactivate', 'restore', 'permissions'], true)) {
    if ($action === 'restore') {
        // A registration rejected by mistake goes back to Pending Approvals, where it can be reviewed and approved again.
        $stmt = $conn->prepare("UPDATE users SET status='pending' WHERE id=? AND status='rejected' AND role <> 'head_admin'");
        $stmt->bind_param('i', $id);
    } elseif ($action === 'deactivate') {
        $status = 'rejected';
        $stmt = $conn->prepare('UPDATE users SET status=? WHERE id=? AND role NOT IN (\'admin\', \'head_admin\')');
        $stmt->bind_param('si', $status, $id);
    } elseif ($action === 'reactivate') {
        // One account per barangay: cannot reactivate a staff account whose barangay is now held by another account.
        $chk = $conn->prepare("SELECT role, barangay, email FROM users WHERE id = ? LIMIT 1");
        $chk->bind_param('i', $id); $chk->execute(); $acc = $chk->get_result()->fetch_assoc(); $chk->close();
        if ($acc && $acc['role'] === 'user' && barangay_account_taken($acc['barangay'], $id, false)) {
            audit_log('ACCOUNT_REACTIVATE_BLOCKED', 'user #' . $id, 'Barangay ' . $acc['barangay'] . ' already has an active account', 'Failed');
            header('Location: admin_approvals.php?tab=rejected&msg=brgy_taken_re'); exit;
        }
        // One Gmail = one active account.
        if ($acc && email_account_taken($acc['email'], $id)) {
            audit_log('ACCOUNT_REACTIVATE_BLOCKED', 'user #' . $id, 'This Gmail already has an active account', 'Failed');
            header('Location: admin_approvals.php?tab=rejected&msg=email_taken'); exit;
        }
        $status = 'approved';
        $stmt = $conn->prepare('UPDATE users SET status=? WHERE id=?');
        $stmt->bind_param('si', $status, $id);
    } else {
        $role = in_array($_POST['role'] ?? '', ['user', 'official'], true) ? $_POST['role'] : 'user';
        $stmt = $conn->prepare('UPDATE users SET role=? WHERE id=? AND role NOT IN (\'admin\', \'head_admin\')');
        $stmt->bind_param('si', $role, $id);
    }
    $changed = 0;
    try {
        if ($stmt) $stmt->execute();
        if ($stmt) $changed = $stmt->affected_rows;
    } catch (mysqli_sql_exception $ex) {
        $changed = 0; // e.g. that email already holds an account with the requested role
    }
    if ($stmt) {
        $stmt->close();
        audit_log('ACCOUNT_' . strtoupper($action), 'user #' . $id, $action === 'permissions' ? 'Role set to ' . $role : ($changed > 0 ? 'Applied' : 'No change (protected or unchanged)'), $changed > 0 ? 'Success' : 'Failed');
    }
}
// Back to User Management, on the tab where the account now appears.
$tab = ['restore' => 'pending', 'reactivate' => 'approved', 'deactivate' => 'rejected'][$action] ?? 'approved';
header('Location: admin_approvals.php?tab=' . $tab);
exit;
