<?php
require_once 'init.php';
require_login();
require_role(['head_admin']);

$create_notice = '';
$create_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
    $c_name = trim((string)($_POST['fullname'] ?? ''));
    $c_email = trim((string)($_POST['email'] ?? ''));
    $c_role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
    $c_barangay = $c_role === 'admin' ? 'Caloocan' : trim((string)($_POST['barangay'] ?? ''));
    $c_position = $c_role === 'admin' ? 'DRRMC Admin' : 'Barangay Staff';
    $c_pass = (string)($_POST['password'] ?? '');
    $c_phone = trim((string)($_POST['phone'] ?? ''));
    $c_address = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255);
    // Optional staff ID image (pre-read by AI through admin_scan_id.php); kept on file like registration IDs.
    $c_id_file = $_FILES['id_image'] ?? null;
    $c_id_ext = '';
    if ($c_id_file && $c_id_file['error'] !== UPLOAD_ERR_NO_FILE) {
        $c_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $c_info = $c_id_file['error'] === UPLOAD_ERR_OK && $c_id_file['size'] <= 8 * 1024 * 1024 ? @getimagesize($c_id_file['tmp_name']) : false;
        if ($c_info && isset($c_types[$c_info['mime']])) $c_id_ext = $c_types[$c_info['mime']];
        else $create_error = 'The ID must be a JPG, PNG or WEBP image no larger than 8 MB.';
    }
    if ($create_error !== '') {
        // invalid ID image, reported above
    } elseif ($c_name === '' || !filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
        $create_error = 'Enter a full name and a valid email address.';
    } elseif ($c_role === 'user' && (!ctype_digit($c_barangay) || (int)$c_barangay < 1 || (int)$c_barangay > 188)) {
        $create_error = 'Select the barangay for this staff account.';
    } elseif (email_account_taken($c_email, 0, true)) {
        $create_error = 'This Gmail already has an account. Only one account is allowed per Gmail.';
    } elseif ($c_role === 'user' && ($c_holder = barangay_account_taken($c_barangay))) {
        $create_error = barangay_taken_message($c_barangay, $c_holder) . ' (' . $c_holder['fullname'] . ')';
    } elseif ($c_phone !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $c_phone)) {
        $create_error = 'Enter a valid contact number, or leave it blank.';
    } elseif (strlen($c_pass) < 8 || !preg_match('/[A-Z]/', $c_pass) || !preg_match('/[0-9]/', $c_pass) || !preg_match('/[^A-Za-z0-9]/', $c_pass)) {
        $create_error = 'Temporary password needs 8+ characters, one uppercase letter, one number, and one symbol.';
    } else {
        $c_id_path = '';
        $c_id_full = '';
        if ($c_id_ext !== '') {
            $c_dir = __DIR__ . '/uploads/ids';
            if (!is_dir($c_dir)) mkdir($c_dir, 0755, true);
            $c_name_file = 'id_' . bin2hex(random_bytes(12)) . '.' . $c_id_ext;
            if (move_uploaded_file($c_id_file['tmp_name'], $c_dir . '/' . $c_name_file)) {
                $c_id_path = 'uploads/ids/' . $c_name_file;
                $c_id_full = $c_dir . '/' . $c_name_file;
            } else {
                $create_error = 'Unable to save the uploaded ID. Please try again.';
            }
        }
        if ($create_error === '') try {
            $c_hash = password_hash($c_pass, PASSWORD_DEFAULT);
            $c_phone_value = $c_phone !== '' ? $c_phone : null;
            $c_stmt = $conn->prepare("INSERT INTO users (fullname, address, phone, barangay, position, verification_file, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')");
            $c_stmt->bind_param('sssssssss', $c_name, $c_address, $c_phone_value, $c_barangay, $c_position, $c_id_path, $c_email, $c_hash, $c_role);
            $c_stmt->execute();
            $c_stmt->close();
            mail_account_status('approved', ['fullname' => $c_name, 'email' => $c_email, 'role' => $c_role, 'barangay' => $c_barangay]);
            audit_log('ACCOUNT_CREATED', $c_email, 'Role ' . $c_role . '; barangay ' . $c_barangay . ($c_id_path !== '' ? '; ID on file' : ''));
            $create_notice = 'Account created and approved for ' . $c_email . '.';
        } catch (mysqli_sql_exception $ex) {
            if ($c_id_full !== '') @unlink($c_id_full);
            $create_error = $ex->getCode() === 1062 ? 'This Gmail already has an account. Only one account is allowed per Gmail.' : 'Unable to create the account.';
        }
    }
}

$pending_count = 0;
$count_result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE status='pending'");
if ($count_result) $pending_count = (int)$count_result->fetch_assoc()['total'];
$users = [];
$result = $conn->query("SELECT id, fullname, email, address, barangay, position, role, verification_file, status, created_at, (SELECT COUNT(*) FROM equipment_reports x WHERE x.user_id = users.id) + (SELECT COUNT(*) FROM item_transactions x WHERE x.processed_by = users.id) + (SELECT COUNT(*) FROM resource_requests x WHERE x.user_id = users.id) + (SELECT COUNT(*) FROM training_requests x WHERE x.user_id = users.id) + (SELECT COUNT(*) FROM training_drills x WHERE x.created_by = users.id) + (SELECT COUNT(*) FROM advertisements x WHERE x.created_by = users.id) AS records FROM users WHERE id <> " . (int)$_SESSION['user_id'] . " ORDER BY created_at DESC");
if ($result) while ($user = $result->fetch_assoc()) $users[] = $user;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Account Approvals &amp; User Management</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .approval-page { max-width:1400px; }
    .approval-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 4px 12px rgba(16,24,40,.06); overflow:hidden; }
    .approval-card-head { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:20px 22px; border-bottom:1px solid #eef0f2; }
    .approval-card-head h3, .layout .approval-card-head h3 { margin:0 0 4px; color:#1f2937; background:none; padding:0; border-radius:0; }
    .approval-card-head p { margin:0; color:#667085; font-size:13px; }
    .approval-count { background:#fff3cd; color:#856404; border-radius:999px; padding:7px 11px; font-size:12px; font-weight:800; white-space:nowrap; }
    .approval-tabs { display:flex; gap:6px; padding:12px 22px 0; border-bottom:1px solid #e5e7eb; flex-wrap:wrap; }
    .approval-tab { border:0; background:transparent; color:#667085; padding:10px 12px; cursor:pointer; font-weight:700; font-size:12px; border-bottom:2px solid transparent; }
    .approval-tab.active { color:#c56a00; border-bottom-color:#ff8c00; }
    .approval-tab b { background:#f59e0b; color:#fff; border-radius:999px; padding:2px 6px; margin-left:3px; }
    .approval-table-wrap { overflow-x:auto; padding:0 22px 22px; }
    .approval-table { width:100%; min-width:900px; border-collapse:collapse; font-size:13px; }
    .approval-table th { padding:13px 10px; background:#f8fafb; color:#667085; font-size:10px; text-align:left; text-transform:uppercase; }
    .approval-table td { padding:13px 10px; border-top:1px solid #eef0f2; color:#344054; vertical-align:middle; }
    .account-cell { display:flex; align-items:center; gap:10px; min-width:220px; }
    .account-avatar,.device-icon { display:grid; place-items:center; border-radius:50%; background:#e8f4ef; color:var(--primary); flex:0 0 auto; }
    .account-avatar { width:34px; height:34px; }
    .account-cell strong,.account-cell small { display:block; }
    .account-cell small { color:#667085; margin-top:2px; }
    .account-address { display:block; color:#667085; font-size:11px; margin-top:3px; max-width:220px; }
    .verification-link { color:var(--primary); font-weight:700; font-size:11px; white-space:nowrap; }
    .verification-missing { color:#98a2b3; font-size:11px; }
    .role-pill,.account-status { display:inline-flex; border-radius:999px; padding:6px 9px; font-size:11px; font-weight:800; white-space:nowrap; }
    .role-pill.staff { background:#dbeafe; color:#1d4ed8; }
    .role-pill.official { background:#ede9fe; color:#6d28d9; }
    .account-status.pending { background:#fef3c7; color:#92400e; }
    .account-status.approved { background:#d1fae5; color:#065f46; }
    .account-status.rejected { background:#fee2e2; color:#991b1b; }
    .approval-restore { background:#fff !important; color:#c56a00 !important; border:1px solid #f5c48a !important; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
    .approval-restore:hover { background:#fff7ed !important; }
    .approval-actions { display:flex; gap:6px; align-items:center; }
    .acct-menu { position:relative; }
    .acct-protected { display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border-radius:8px; background:#f2f4f7; color:#475467; font-size:12px; font-weight:700; white-space:nowrap; }
    .acct-menu-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border:1px solid #f5c48a; border-radius:8px; background:#fff; color:#9a4a00; font:inherit; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; }
    .acct-menu-btn:hover, .acct-menu-btn[aria-expanded="true"] { background:#fff7ed; }
    .acct-menu-btn i { font-size:10px; }
    .acct-menu-list { position:absolute; right:0; top:calc(100% + 6px); z-index:50; width:220px; padding:6px; background:#fff; border:1px solid #eaecf0; border-radius:10px; box-shadow:0 12px 28px rgba(16,24,40,.16); }
    .acct-menu-list form { margin:0; }
    .acct-menu-list button { display:flex; align-items:center; gap:8px; width:100%; margin:0; padding:9px 10px; border:0; border-radius:6px; background:transparent; color:#344054; font:inherit; font-size:13px; font-weight:600; text-align:left; cursor:pointer; text-transform:none; letter-spacing:0; }
    .acct-menu-list button:hover { background:#f9fafb; }
    .acct-menu-list button.warn { color:#b54708; } .acct-menu-list button.warn:hover { background:#fffaeb; }
    .acct-menu-list button.danger { color:#b42318; } .acct-menu-list button.danger:hover { background:#fef3f2; }
    .acct-locked { display:block; padding:8px 10px; margin-top:4px; border-top:1px solid #f2f4f7; color:#98a2b3; font-size:12px; font-weight:600; }
    .acct-locked small { display:block; margin-top:2px; font-weight:400; line-height:1.4; }
    .approval-actions form { margin:0; }
    .approval-actions .btn { padding:7px 9px; font-size:10px; white-space:nowrap; }
    .approval-approve { background:#198754; }
    .approval-delete { background:#fff !important; color:#b42318 !important; border:1px solid #fda29b !important; }
    .approval-delete:hover:not([disabled]) { background:#fef3f2 !important; }
    .approval-delete[disabled] { color:#98a2b3 !important; border-color:#e4e7ec !important; cursor:not-allowed; }
    /* !important: keep these outlined; the theme gives every .btn a gradient fill */
    .approval-reject,.deactivate-button { background:#fff !important; color:#dc3545 !important; border:1px solid #f0a4ad !important; }
    .approval-reject:hover,.deactivate-button:hover { background:#fff5f5 !important; filter:none !important; }
    .permission-button { background:#fff !important; color:#c56a00 !important; border:1px solid #f5c48a !important; }
    .permission-button:hover { background:#fff7ed !important; filter:none !important; }
    .acct-menu-list .permission-button { border:0 !important; background:transparent !important; color:#344054 !important; }
    .acct-menu-list .permission-button:hover { background:#f9fafb !important; }
    .approval-row-hidden { display:none; }
    .tab-empty { margin:0; padding:26px 10px; text-align:center; color:#98a2b3; font-size:13px; }
    /* Two sections: Account Approvals (default) and Create Account, one shown at a time. */
    .approval-switch { display:inline-flex; gap:4px; margin-bottom:16px; padding:5px; background:rgba(255,255,255,.85); border:1px solid rgba(255,255,255,.9); border-radius:12px; box-shadow:0 4px 12px rgba(120,60,0,.1); flex-wrap:wrap; }
    .approval-switch-btn { border:0; background:transparent; color:#475467; padding:10px 16px; border-radius:9px; font:inherit; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .approval-switch-btn:hover { background:#fff7ed; color:#c56a00; }
    .approval-switch-btn.active { background:var(--theme-strong); color:#fff; box-shadow:0 4px 12px rgba(234,122,0,.3); }
    .approval-switch-btn b { background:#f59e0b; color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; }
    .approval-switch-btn.active b { background:rgba(255,255,255,.28); }
    .approval-panel[hidden] { display:none; }
    .create-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; padding:18px 22px 22px; align-items:end; }
    .create-form label { display:flex; flex-direction:column; gap:6px; margin:0; font-size:12px; font-weight:700; color:#344054; text-transform:uppercase; letter-spacing:.03em; }
    .create-form input, .create-form select { width:100%; box-sizing:border-box; height:44px; padding:0 12px; border:1px solid #d0d5dd; border-radius:8px; background:#fff; font:inherit; font-size:14px; text-transform:none; letter-spacing:0; color:#1f2937; }
    .create-form input:focus, .create-form select:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .create-form .btn { height:44px; }
    .create-form .create-wide { grid-column:span 2; }
    /* Staff ID upload + AI scan, full width above the fields */
    .create-id { grid-column:1 / -1; display:flex; flex-wrap:wrap; align-items:center; gap:14px 18px; padding:14px 16px; border:1.5px dashed #f0b060; border-radius:12px; background:#fffaf4; }
    .create-id-text { flex:1 1 260px; min-width:0; }
    .create-id-text strong { display:block; color:#1f2937; font-size:14px; }
    .create-id-text strong i { color:#ea7a00; margin-right:4px; }
    .create-id-text strong span { color:#98a2b3; font-weight:600; font-size:12px; }
    .create-id-text small { display:block; margin-top:3px; color:#667085; font-size:12px; line-height:1.5; }
    .create-form .create-id-pick { display:inline-flex; flex-direction:row; margin:0; cursor:pointer; text-transform:none; letter-spacing:0; }
    .create-id-pick input { position:absolute; width:1px; height:1px; opacity:0; }
    .create-id-pick span { display:inline-flex; align-items:center; gap:7px; padding:10px 16px; border-radius:8px; background:#fff; border:1px solid #f5c48a; color:#c56a00; font-size:13px; font-weight:700; }
    .create-id-pick:hover span, .create-id-pick input:focus-visible + span { background:#fff1e0; }
    .create-id-preview { display:flex; align-items:center; gap:10px; }
    .create-id-preview[hidden] { display:none; }
    .create-id-preview img { width:92px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; background:#fff; }
    .create-id-rescan { border:1px solid #c7d7fe; background:#eef4ff; color:#1d4ed8; border-radius:8px; padding:8px 12px; font:inherit; font-size:12px; font-weight:700; cursor:pointer; }
    .create-id-rescan:disabled { opacity:.55; cursor:wait; }
    .create-id-status { flex-basis:100%; padding:9px 12px; border-radius:8px; font-size:12.5px; line-height:1.5; }
    .create-id-status[hidden] { display:none; }
    .create-id-status.busy { background:#eef4ff; color:#1d4ed8; }
    .create-id-status.ok { background:#ecfdf3; color:#067647; }
    .create-id-status.err { background:#fef3f2; color:#b42318; }
    .create-form input.ai-filled, .create-form select.ai-filled { background:#fffbe6; border-color:#f5c48a; }
    @media(max-width:700px) { .create-form .create-wide { grid-column:auto; } }
    /* Pending applicants open the review popup (ID, AI re-scan, approve/reject). */
    .account-open { width:100%; border:1px solid transparent; border-radius:10px; background:transparent; padding:6px 8px; margin:-6px -8px; text-align:left; font:inherit; color:inherit; cursor:pointer; }
    .account-open:hover, .account-open:focus-visible { background:#fff7ed; border-color:#f5c48a; outline:none; }
    .account-open strong { color:#1f2937; }
    .account-open em { display:block; margin-top:4px; font-style:normal; font-size:11px; font-weight:700; color:#c56a00; }
    @media(max-width:700px) { .approval-card-head { align-items:flex-start; flex-direction:column; } }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;"><h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-users-gear" style="color:var(--primary);margin-right:8px;"></i>Account Approvals &amp; User Management</h2><p style="margin:4px 0 0;color:#667085;font-size:13px;">Head Admin access for reviewing staff and DRRMC Official registrations.</p></header>
    <main class="main-content" style="flex:1;overflow-y:auto;"><div class="container approval-page"><?php if (($_GET['msg'] ?? '') === 'deleted'): ?><div class="notice" style="margin-bottom:14px;">Account deleted permanently.</div><?php endif; ?><?php if (($_GET['msg'] ?? '') === 'delete_blocked'): ?><div class="errors" style="margin-bottom:14px;">This account has saved records (equipment reports, requests or transactions), so it can't be deleted without erasing the barangay's history. Deactivate it instead.</div><?php endif; ?><?php if (($_GET['msg'] ?? '') === 'brgy_taken'): ?><div class="errors" style="margin-bottom:14px;"><?php echo e(barangay_label((int)($_GET['b'] ?? 0)) . ' already has an approved staff account. Only one account is allowed per barangay: deactivate the old account first, or reject this registration.'); ?></div><?php endif; ?><?php if (($_GET['msg'] ?? '') === 'email_taken'): ?><div class="errors" style="margin-bottom:14px;">This Gmail already has an active account. Only one account is allowed per Gmail.</div><?php endif; ?><?php if (($_GET['msg'] ?? '') === 'brgy_taken_re'): ?><div class="errors" style="margin-bottom:14px;">This account can't be reactivated: its barangay already has another active staff account. Only one account is allowed per barangay.</div><?php endif; ?><?php if ($create_notice): ?><div class="notice" style="margin-bottom:14px;"><?php echo e($create_notice); ?></div><?php endif; ?><?php if ($create_error): ?><div class="errors" style="margin-bottom:14px;"><?php echo e($create_error); ?></div><?php endif; ?><?php $show_create = (bool)$create_error; ?><div class="approval-switch" role="tablist" aria-label="User management sections"><button type="button" class="approval-switch-btn<?php echo $show_create ? '' : ' active'; ?>" data-panel="panelApprovals" role="tab"><i class="fa-solid fa-user-check"></i> Account Approvals <b><?php echo $pending_count; ?></b></button><button type="button" class="approval-switch-btn<?php echo $show_create ? ' active' : ''; ?>" data-panel="panelCreate" role="tab"><i class="fa-solid fa-user-plus"></i> Create Account</button></div><section class="approval-card approval-panel" id="panelCreate"<?php echo $show_create ? '' : ' hidden'; ?>><div class="approval-card-head"><div><h3>Create Account</h3><p>Issue an approved account directly. The user should change the temporary password after first login.</p></div></div><?php $keep = function ($k) use ($create_error) { return $create_error ? (string)($_POST[$k] ?? '') : ''; }; ?><form method="post" class="create-form" enctype="multipart/form-data" id="createForm"><input type="hidden" name="create_account" value="1"><div class="create-id"><div class="create-id-text"><strong><i class="fa-solid fa-id-card"></i> Staff ID <span>(optional)</span></strong><small>Upload a photo of the staff member's ID. AI reads it and fills in the details below; check them before creating the account.</small></div><label class="create-id-pick"><input type="file" name="id_image" id="createIdFile" accept="image/jpeg,image/png,image/webp"><span><i class="fa-solid fa-upload"></i> Choose ID image</span></label><div class="create-id-preview" id="createIdPreview" hidden><img id="createIdImg" alt="Selected ID"><button type="button" class="create-id-rescan" id="createIdRescan"><i class="fa-solid fa-rotate"></i> Re-scan</button></div><div class="create-id-status" id="createIdStatus" hidden></div></div><label>Full name<input name="fullname" id="createName" required value="<?php echo e($keep('fullname')); ?>"></label><label>Email<input name="email" type="email" required value="<?php echo e($keep('email')); ?>"></label><label>Account type<select name="role" id="createRole"><option value="user">Barangay Staff (User)</option><option value="admin"<?php echo $keep('role') === 'admin' ? ' selected' : ''; ?>>DRRMC Admin</option></select></label><label id="createBarangayWrap">Barangay<select name="barangay" id="createBarangay"><option value="">Select Barangay</option><?php echo barangay_options_html($keep('barangay')); ?></select></label><label>Contact number<input name="phone" id="createPhone" placeholder="09XX XXX XXXX" value="<?php echo e($keep('phone')); ?>"></label><label class="create-wide">Address<input name="address" id="createAddress" maxlength="255" value="<?php echo e($keep('address')); ?>"></label><label>Temporary password<input name="password" type="password" autocomplete="new-password" required></label><button class="btn" type="submit">Create Account</button></form></section><section class="approval-card approval-panel" id="panelApprovals"<?php echo $show_create ? ' hidden' : ''; ?>><div class="approval-card-head"><div><h3>Account Approvals &amp; User Management</h3><p>Review registration requests and manage account access.</p></div><span class="approval-count">Account Approvals (<?php echo $pending_count; ?> Pending)</span></div><div class="approval-tabs" role="tablist"><button type="button" class="approval-tab active" data-filter="pending">Pending Approvals <b><?php echo $pending_count; ?></b></button><button type="button" class="approval-tab" data-filter="approved">Approved Accounts</button><button type="button" class="approval-tab" data-filter="rejected">Rejected / Inactive</button></div><div class="approval-table-wrap"><table class="approval-table"><thead><tr><th>Full Name &amp; Email</th><th>Requested Role</th><th>Assigned Barangay / District</th><th>Address</th><th>Verification ID</th><th>Date Requested</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($users as $account): $official = strtolower((string)$account['role']) === 'official' || in_array($account['position'], ['Officer','Coordinator','DRRM Officer','Barangay Coordinator','Official','DRRMC Official'], true); $filter = $account['status'] === 'pending' ? 'pending' : ($account['status'] === 'approved' ? 'approved' : 'rejected'); ?><tr data-row="<?php echo $filter; ?>"><td><?php if ($filter === 'pending'): ?><button type="button" class="account-cell account-open" data-review="<?php echo (int)$account['id']; ?>" title="Open review: check the ID, re-scan it with AI, approve or reject"><span class="account-avatar"><i class="fa-solid fa-user"></i></span><span><strong><?php echo e($account['fullname']); ?></strong><small><?php echo e($account['email']); ?></small><em><i class="fa-solid fa-rotate"></i> Review &amp; re-scan ID</em></span></button><?php else: ?><div class="account-cell"><span class="account-avatar"><i class="fa-solid fa-user"></i></span><span><strong><?php echo e($account['fullname']); ?></strong><small><?php echo e($account['email']); ?></small></span></div><?php endif; ?></td><td><span class="role-pill <?php echo $official ? 'official' : 'staff'; ?>"><?php echo $official ? 'DRRMC Official' : 'Barangay Staff'; ?></span></td><td><?php echo e($account['barangay'] ? 'Barangay ' . $account['barangay'] : 'South Caloocan Division'); ?></td><td><span class="account-address"><?php echo e($account['address'] ?: 'Address not provided'); ?></span></td><td><?php if (!empty($account['verification_file'])): ?><a class="verification-link" href="view_id.php?id=<?php echo (int)$account['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-id-card"></i> View ID</a><?php else: ?><span class="verification-missing">Not uploaded</span><?php endif; ?></td><td><?php echo e(date('M j, Y', strtotime($account['created_at']))); ?></td><td><span class="account-status <?php echo $filter; ?>"><?php echo $filter === 'pending' ? 'Pending Approval' : ($filter === 'approved' ? 'Active' : 'Rejected'); ?></span></td><td><div class="approval-actions"><?php if ($filter === 'pending'): ?><form method="post" action="approve_user.php"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="return_to" value="admin_approvals.php"><button class="btn approval-approve" type="submit">Approve</button></form><form method="post" action="reject_user.php" class="reject-form"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="return_to" value="admin_approvals.php"><button class="btn approval-reject" type="submit">Reject</button></form><?php elseif ($filter === 'approved' && $account['role'] === 'head_admin'): ?><span class="acct-protected" title="Head Admin accounts cannot be deactivated or deleted"><i class="fa-solid fa-shield-halved"></i> Protected</span><?php elseif ($filter === 'approved'): $recs = (int)$account['records']; ?><div class="acct-menu"><button type="button" class="acct-menu-btn" aria-haspopup="true" aria-expanded="false">Manage <i class="fa-solid fa-chevron-down"></i></button><div class="acct-menu-list" hidden><?php if (!in_array($account['role'], ['admin', 'head_admin'], true)): ?><form method="post" action="manage_user.php" class="deactivate-form"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="action" value="deactivate"><button type="submit" class="warn" data-name="<?php echo e($account['fullname']); ?>"><i class="fa-solid fa-user-slash"></i> Deactivate</button></form><?php else: ?><span class="acct-locked" style="border-top:0;margin-top:0;"><i class="fa-solid fa-shield-halved"></i> DRRMC Admin account<small>Can't be deactivated; delete it instead if it is no longer used.</small></span><?php endif; ?><?php if ($recs === 0): ?><form method="post" action="manage_user.php" class="delete-form"><input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="tab" value="approved"><button type="submit" class="danger" data-name="<?php echo e($account['fullname']); ?>"><i class="fa-solid fa-trash"></i> Delete account</button></form><?php else: ?><span class="acct-locked"><i class="fa-solid fa-lock"></i> Delete not available<small>Has <?php echo $recs; ?> saved record(s); deactivate instead to keep the barangay's history.</small></span><?php endif; ?></div></div><?php else: $was_active = in_array($account['role'], ['admin', 'official'], true) || ctype_digit((string)$account['barangay']); ?><form method="post" action="manage_user.php" class="restore-form"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="action" value="restore"><button class="btn approval-restore" type="submit" title="Move this account back to Pending Approvals so it can be reviewed and approved"><i class="fa-solid fa-rotate-left"></i> Restore to Pending</button></form><?php if ($was_active): ?><form method="post" action="manage_user.php"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="action" value="reactivate"><button class="btn approval-approve" type="submit">Reactivate</button></form><?php endif; ?><?php endif; ?><?php if ($account['role'] !== 'head_admin' && $filter !== 'approved'): $recs = (int)$account['records']; ?><?php if ($recs === 0): ?><form method="post" action="manage_user.php" class="delete-form"><input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$account['id']; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="tab" value="<?php echo $filter; ?>"><button class="btn approval-delete" type="submit" data-name="<?php echo e($account['fullname']); ?>" title="Delete this account permanently"><i class="fa-solid fa-trash"></i> Delete</button></form><?php else: ?><button type="button" class="btn approval-delete" disabled title="<?php echo e('Has ' . $recs . ' saved record(s) (equipment reports, requests, audit-log transactions). Deleting would erase the barangay\'s history, so deactivate this account instead.'); ?>"><i class="fa-solid fa-lock"></i> Delete</button><?php endif; ?><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table><p class="tab-empty" id="tabEmpty" hidden></p><?php if (!$users): ?><p style="color:#667085;padding:18px 0;">No user accounts found.</p><?php endif; ?></div></section></div></main>
  </div>
</div>
<script>
document.querySelectorAll('[data-filter]').forEach(function(tab){tab.addEventListener('click',function(){var filter=tab.dataset.filter;document.querySelectorAll('[data-filter]').forEach(function(item){item.classList.toggle('active',item===tab);});document.querySelectorAll('[data-row]').forEach(function(row){row.classList.toggle('approval-row-hidden',row.dataset.row!==filter);});});});
(function(){var t=new URLSearchParams(location.search).get('tab');var b=t&&document.querySelector('[data-filter="'+t+'"]');if(b)b.click();})();
document.addEventListener('click',function(e){var b=e.target.closest('.acct-menu-btn');document.querySelectorAll('.acct-menu').forEach(function(m){var btn=m.querySelector('.acct-menu-btn'),open=btn===b&&btn.getAttribute('aria-expanded')!=='true';if(!m.contains(e.target)||btn===b){m.querySelector('.acct-menu-list').hidden=!open;btn.setAttribute('aria-expanded',open?'true':'false');}});});
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.acct-menu-list').forEach(function(l){l.hidden=true;l.previousElementSibling.setAttribute('aria-expanded','false');});});
document.querySelectorAll('.deactivate-form').forEach(function(form){form.addEventListener('submit',function(event){var n=form.querySelector('button').dataset.name||'this account';if(!window.confirm('Deactivate '+n+'? They will no longer be able to sign in. You can reactivate the account later.'))event.preventDefault();});});
document.querySelectorAll('.delete-form').forEach(function(form){form.addEventListener('submit',function(event){var n=form.querySelector('button').dataset.name||'this account';if(!window.confirm('Delete '+n+' permanently?\n\nThe account, its uploaded ID and its messages will be removed. This cannot be undone.'))event.preventDefault();});});
document.querySelectorAll('.restore-form').forEach(function(form){form.addEventListener('submit',function(event){if(!window.confirm('Move this account back to Pending Approvals? You can then review and approve it.'))event.preventDefault();});});
document.querySelectorAll('.reject-form').forEach(function(form){form.addEventListener('submit',function(event){var reason=window.prompt('Optional reason for rejection:','');if(reason===null){event.preventDefault();return;}var input=document.createElement('input');input.type='hidden';input.name='reason';input.value=reason;form.appendChild(input);});});
document.querySelectorAll('.permission-button').forEach(function(button){button.addEventListener('click',function(){var role=window.prompt('Enter permission role: user or official','user');if(role!=='user'&&role!=='official')return;var form=document.createElement('form');form.method='post';form.action='manage_user.php';[['id',button.dataset.id],['action','permissions'],['role',role]].forEach(function(pair){var input=document.createElement('input');input.type='hidden';input.name=pair[0];input.value=pair[1];form.appendChild(input);});document.body.appendChild(form);form.submit();});});
(function(){var act=document.querySelector('.approval-tab.active'),f=act?act.dataset.filter:'pending';document.querySelectorAll('[data-row]').forEach(function(row){row.classList.toggle('approval-row-hidden',row.dataset.row!==f);});})();
function approvalEmpty(){var act=document.querySelector('.approval-tab.active'),f=act?act.dataset.filter:'pending',shown=document.querySelectorAll('[data-row="'+f+'"]').length,p=document.getElementById('tabEmpty');if(!p)return;p.hidden=shown>0;p.textContent={pending:'No pending registrations right now.',approved:'No approved accounts yet.',rejected:'No rejected or inactive accounts.'}[f]||'';}
document.querySelectorAll('[data-filter]').forEach(function(t){t.addEventListener('click',approvalEmpty);});approvalEmpty(); // show the rows of whichever tab is open (e.g. ?tab=rejected after an action)
document.querySelectorAll('[data-panel]').forEach(function(btn){btn.addEventListener('click',function(){document.querySelectorAll('[data-panel]').forEach(function(b){b.classList.toggle('active',b===btn);document.getElementById(b.dataset.panel).hidden=b!==btn;});});});
document.querySelectorAll('[data-review]').forEach(function(btn){btn.addEventListener('click',function(){if(typeof window.hqReview==='function')window.hqReview(btn.dataset.review);});});
// Create Account: choosing an ID image sends it to admin_scan_id.php; the AI's reading pre-fills the form (highlighted) for the Head Admin to check.
(function () {
  var TOKEN = '<?php echo e(csrf_token()); ?>';
  var file = document.getElementById('createIdFile'), status = document.getElementById('createIdStatus');
  var preview = document.getElementById('createIdPreview'), img = document.getElementById('createIdImg'), rescanBtn = document.getElementById('createIdRescan');
  var submitBtn = document.querySelector('#createForm button[type="submit"]');
  if (!file) return;
  function show(kind, text) { status.hidden = false; status.className = 'create-id-status ' + kind; status.textContent = text; }
  function fill(id, value) {
    var field = document.getElementById(id);
    if (!field || value === null || value === undefined || value === '') return false;
    field.value = String(value);
    field.classList.add('ai-filled');
    field.dispatchEvent(new Event('change'));
    return true;
  }
  function scan() {
    var f = file.files[0];
    if (!f) return;
    if (f.size > 5 * 1024 * 1024) { show('err', 'This image is too large to scan (5 MB max). It will still be saved with the account; fill in the details by hand.'); return; }
    var body = new FormData();
    body.append('csrf', TOKEN);
    body.append('id_image', f);
    show('busy', 'Reading the ID with AI…');
    rescanBtn.disabled = true; submitBtn.disabled = true;
    fetch('admin_scan_id.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { show('err', d.message || 'Could not read this ID. Fill in the details by hand.'); return; }
        var fl = d.fields, found = [];
        if (fill('createName', fl.full_name)) found.push('name');
        if (fl.staff_type === 'drrmc') { fill('createRole', 'admin'); found.push('DRRMC Admin'); }
        else if (fl.staff_type === 'barangay') { fill('createRole', 'user'); found.push('Barangay Staff'); }
        if (fl.staff_type !== 'drrmc' && fill('createBarangay', fl.barangay)) found.push('Barangay ' + fl.barangay);
        if (fill('createPhone', fl.phone)) found.push('contact number');
        if (fill('createAddress', fl.address)) found.push('address');
        var text = found.length ? 'Filled from the ID: ' + found.join(', ') + '.' : 'No details could be read from this ID; fill them in by hand.';
        if (d.evidence) text += ' AI saw: "' + d.evidence + '" (' + d.confidence + ' confidence).';
        if (d.note) text += ' ' + d.note;
        text += ' Please check the highlighted fields, then add the email and temporary password.';
        show(d.note ? 'err' : 'ok', text);
      })
      .catch(function () { show('err', 'Scanning failed (network error). Fill in the details by hand.'); })
      .then(function () { rescanBtn.disabled = false; submitBtn.disabled = false; });
  }
  file.addEventListener('change', function () {
    document.querySelectorAll('.create-form .ai-filled').forEach(function (el) { el.classList.remove('ai-filled'); });
    var f = file.files[0];
    if (!f) { preview.hidden = true; status.hidden = true; return; }
    img.src = URL.createObjectURL(f);
    preview.hidden = false;
    scan();
  });
  rescanBtn.addEventListener('click', scan);
  document.querySelectorAll('.create-form input, .create-form select').forEach(function (el) {
    el.addEventListener('input', function () { el.classList.remove('ai-filled'); });
  });
}());
(function(){var role=document.getElementById('createRole'),wrap=document.getElementById('createBarangayWrap');if(!role||!wrap)return;function sync(){wrap.style.display=role.value==='admin'?'none':'';}role.addEventListener('change',sync);sync();}());
</script></body></html>
