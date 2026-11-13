<?php
require_once 'init.php';
require_login();
require_role(['head_admin','official','admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0){
        // fetch user details (email, fullname, position, barangay)
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

        // Authorization: officials can approve staff in their barangay; head_admin approves officials
        if ($target_is_official) {
            // only head admin can approve officials
            require_role(['head_admin']);
        } else {
            // staff approval: allow official (same barangay) or head_admin
            if (!is_role_in(['admin','head_admin'])) {
                header('Location: ' . $back . '?msg=forbidden'); exit;
            }
            if (is_role_in(['admin']) ) {
                // official must be approving for their barangay
                $my_barangay = $_SESSION['user_barangay'] ?? '';
                if ($my_barangay === '' || $my_barangay != $user['barangay']) {
                    header('Location: ' . $back . '?msg=forbidden'); exit;
                }
            }
        }

        // set role according to position: officials -> 'official', staff -> 'user'
        $new_role = $target_is_official ? 'official' : 'user';
        // One account per barangay: an approved account already holding this barangay blocks the approval.
        if ($new_role === 'user' && barangay_account_taken($user['barangay'], $id, false)) {
            audit_log('ACCOUNT_APPROVAL_BLOCKED', (string)$user['email'], 'Barangay ' . $user['barangay'] . ' already has an approved account', 'Failed');
            header('Location: ' . $back . '?msg=brgy_taken&b=' . urlencode((string)$user['barangay'])); exit;
        }
        // One Gmail = one active account.
        if (email_account_taken($user['email'], $id)) {
            audit_log('ACCOUNT_APPROVAL_BLOCKED', (string)$user['email'], 'This Gmail already has an active account', 'Failed');
            header('Location: ' . $back . '?msg=email_taken'); exit;
        }
        $stmt = $conn->prepare('UPDATE users SET status = ?, role = ? WHERE id = ?');
        $approved = 'approved';
        $stmt->bind_param('ssi', $approved, $new_role, $id);
        $stmt->execute();
        audit_log('ACCOUNT_APPROVED', (string)$user['email'], 'Role set to ' . $new_role . '; barangay ' . $user['barangay']);

        // send notification email
        if ($user && !empty($user['email'])){
            $mail_ok = mail_account_status('approved', ['role' => $new_role] + $user);
            $mail_status = $mail_ok ? 'sent' : 'logged';
        }
    }
}
header('Location: ' . ($back ?? 'admin_reports.php') . '?msg=approved' . (isset($mail_status) ? '&mail=' . $mail_status : '')); exit;
