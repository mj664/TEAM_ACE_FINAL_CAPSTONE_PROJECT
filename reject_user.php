<?php
require_once 'init.php';
require_login();
require_role(['head_admin','official','admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0){
        // fetch user details
        $s = $conn->prepare('SELECT email, fullname, position, role, barangay FROM users WHERE id = ? LIMIT 1');
        $s->bind_param('i', $id);
        $s->execute();
        $r = $s->get_result();
        $user = $r ? $r->fetch_assoc() : null;

        $back = admin_return_page($_POST['return_to'] ?? 'admin_reports.php');

        if (!$user) {
            header('Location: ' . $back . '?msg=notfound'); exit;
        }

        $pos = $user['position'] ?? '';
        $target_is_official = strtolower((string)($user['role'] ?? '')) === 'official'
            || in_array($pos, ['Officer','Coordinator','DRRM Officer','Barangay Coordinator','Official','DRRMC Official'], true);

        if ($target_is_official) {
            // only head admin can reject officials
            require_role(['head_admin']);
        } else {
            // staff rejection: allow official (same barangay) or head_admin
            if (!is_role_in(['admin','head_admin'])) {
                header('Location: admin_reports.php?msg=forbidden'); exit;
            }
            if (is_role_in(['admin'])) {
                $my_barangay = $_SESSION['user_barangay'] ?? '';
                if ($my_barangay === '' || $my_barangay != $user['barangay']) {
                    header('Location: admin_reports.php?msg=forbidden'); exit;
                }
            }
        }

        $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
        $status = 'rejected';
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();

        $reason = trim((string)($_POST['reason'] ?? ''));
        audit_log('ACCOUNT_REJECTED', (string)$user['email'], $reason !== '' ? 'Reason: ' . $reason : 'No reason given');

        if ($user && !empty($user['email'])){
            $mail_ok = mail_account_status('rejected', $user, $reason);
            $mail_status = $mail_ok ? 'sent' : 'logged';
        }
    }
}
header('Location: ' . ($back ?? 'admin_reports.php') . '?msg=rejected' . (isset($mail_status) ? '&mail=' . $mail_status : '')); exit;
