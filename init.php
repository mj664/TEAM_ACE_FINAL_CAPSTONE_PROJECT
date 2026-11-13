<?php
session_start();
require_once __DIR__ . '/config.php';


// Keep existing installations compatible with sectioned inventory.
$inventory_section_column = $conn->query("SHOW COLUMNS FROM inventory LIKE 'section'");
if ($inventory_section_column && $inventory_section_column->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD section VARCHAR(100) NOT NULL DEFAULT 'Other' AFTER item_description");
}
$conn->query("UPDATE inventory SET section = CASE
        WHEN LOWER(item_description) LIKE '%ambulance%' OR LOWER(item_description) LIKE '%fire truck%' THEN 'Vehicles'
        WHEN LOWER(item_description) LIKE '%facemask%' OR LOWER(item_description) LIKE '%bandage%' OR LOWER(item_description) LIKE '%gloves%'
            OR LOWER(item_description) LIKE '%betadine%' OR LOWER(item_description) LIKE '%tape%' OR LOWER(item_description) LIKE '%gauze%'
            OR LOWER(item_description) LIKE '%cannula%' OR LOWER(item_description) LIKE '%oxygen mask%' THEN 'Medical Supplies'
        WHEN LOWER(item_description) LIKE '%oxygen%' OR LOWER(item_description) LIKE '%stretcher%' OR LOWER(item_description) LIKE '%strap%'
            OR LOWER(item_description) LIKE '%bp%' OR LOWER(item_description) LIKE '%oximeter%' OR LOWER(item_description) LIKE '%splint%'
            OR LOWER(item_description) LIKE '%collar%' OR LOWER(item_description) LIKE '%flashlight%' OR LOWER(item_description) LIKE '%rope%'
            OR LOWER(item_description) LIKE '%helmet%' OR LOWER(item_description) LIKE '%harness%' OR LOWER(item_description) LIKE '%jacket%'
            OR LOWER(item_description) LIKE '%hazmat%' OR LOWER(item_description) LIKE '%defibrillator%' THEN 'Tools & Equipment'
        ELSE section END
    WHERE section = 'Other'");

// Shared user/admin contact messages. CREATE IF NOT EXISTS keeps existing installs compatible.
$conn->query("CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_id INT NULL,
    sender_role VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_contact_user (user_id),
    INDEX idx_contact_unread (is_read, sender_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS security_login_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    location VARCHAR(120) NOT NULL DEFAULT 'Caloocan, PH',
    device VARCHAR(160) NOT NULL,
    status ENUM('Success','Failed') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_activity_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS security_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_key VARCHAR(64) NOT NULL UNIQUE,
    device VARCHAR(160) NOT NULL,
    device_type ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
    ip_address VARCHAR(45) NOT NULL,
    location VARCHAR(120) NOT NULL DEFAULT 'Caloocan, PH',
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_security_sessions_user (user_id, revoked),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS security_preferences (
    user_id INT PRIMARY KEY,
    email_new_device TINYINT(1) NOT NULL DEFAULT 1,
    email_password_change TINYINT(1) NOT NULL DEFAULT 1,
    email_failed_login TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device VARCHAR(160) NOT NULL,
    device_key VARCHAR(64) NOT NULL,
    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_trusted_device (user_id, device_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS resource_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    request_type VARCHAR(100) NOT NULL,
    items_requested TEXT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by INT NULL,
    seed_key VARCHAR(100) NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_resource_requests_user (user_id),
    INDEX idx_resource_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$resource_status_column = $conn->query("SHOW COLUMNS FROM resource_requests LIKE 'status'");
if ($resource_status_column && ($resource_status = $resource_status_column->fetch_assoc()) && strpos($resource_status['Type'], "'deleted'") === false) {
    $conn->query("ALTER TABLE resource_requests MODIFY status ENUM('pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending'");
}
$conn->query("CREATE TABLE IF NOT EXISTS training_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    training_name VARCHAR(150) NOT NULL,
    requested_date DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    details TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_training_requests_user (user_id),
    INDEX idx_training_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS advertisements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_advertisements_active (is_active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$training_reply_column = $conn->query("SHOW COLUMNS FROM training_requests LIKE 'admin_reply'");
if ($training_reply_column && $training_reply_column->num_rows === 0) {
    $conn->query("ALTER TABLE training_requests ADD admin_reply TEXT NULL AFTER details");
}
$training_schedule_column = $conn->query("SHOW COLUMNS FROM training_requests LIKE 'scheduled_at'");
if ($training_schedule_column && $training_schedule_column->num_rows === 0) {
    $conn->query("ALTER TABLE training_requests ADD scheduled_at DATETIME NULL AFTER requested_date");
}
$conn->query("CREATE TABLE IF NOT EXISTS training_drills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_name VARCHAR(150) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    location VARCHAR(255) NOT NULL,
    scope TEXT NOT NULL,
    participants VARCHAR(255) NOT NULL,
    duration VARCHAR(100) NOT NULL,
    status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    request_id INT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES training_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_training_drills_date (scheduled_at),
    INDEX idx_training_drills_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// City-level audit trail reviewed by the Head Admin.
$conn->query("CREATE TABLE IF NOT EXISTS tbl_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    actor_label VARCHAR(190) NULL,
    actor_role VARCHAR(30) NULL,
    action VARCHAR(80) NOT NULL,
    target VARCHAR(255) NULL,
    details TEXT NULL,
    status ENUM('Success','Failed') NOT NULL DEFAULT 'Success',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Budget & expense transparency module (ledger, notices, expense packets, AI verification, review): installed once from budget_transparency.sql.
$budget_probe = $conn->query("SHOW TABLES LIKE 'tbl_budget_allocation'");
if ($budget_probe && $budget_probe->num_rows === 0) {
    require_once __DIR__ . '/budget_lib.php';
    budget_install_schema($conn);
}

$profile_logo_column = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_logo'");
if ($profile_logo_column && $profile_logo_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD profile_logo VARCHAR(255) NULL AFTER verification_file");
}
$conn->query("UPDATE users SET profile_logo = 'assets/cdrrmd-logo.jpg'
    WHERE role IN ('admin', 'head_admin') AND (profile_logo IS NULL OR profile_logo = '')");
$two_factor_enabled_column = $conn->query("SHOW COLUMNS FROM users LIKE 'two_factor_enabled'");
if ($two_factor_enabled_column && $two_factor_enabled_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_logo");
}
$two_factor_method_column = $conn->query("SHOW COLUMNS FROM users LIKE 'two_factor_method'");
if ($two_factor_method_column && $two_factor_method_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD two_factor_method VARCHAR(40) NOT NULL DEFAULT 'authenticator' AFTER two_factor_enabled");
}
$password_changed_column = $conn->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'");
if ($password_changed_column && $password_changed_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD password_changed_at DATETIME NULL AFTER password");
}
$two_factor_verified_column = $conn->query("SHOW COLUMNS FROM users LIKE 'two_factor_verified_at'");
if ($two_factor_verified_column && $two_factor_verified_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD two_factor_verified_at DATETIME NULL AFTER two_factor_method");
}

// System (AI) messages have no human sender, so sender_id must be optional.
$sender_column = $conn->query("SHOW COLUMNS FROM contact_messages LIKE 'sender_id'");
if ($sender_column && ($sender_info = $sender_column->fetch_assoc()) && $sender_info['Null'] === 'NO') {
    $conn->query("ALTER TABLE contact_messages MODIFY sender_id INT NULL");
}
// Marks messages that the restocking engine sent on behalf of a DRRMC staff account.
$auto_sent_column = $conn->query("SHOW COLUMNS FROM contact_messages LIKE 'auto_sent'");
if ($auto_sent_column && $auto_sent_column->num_rows === 0) {
    $conn->query("ALTER TABLE contact_messages ADD auto_sent TINYINT(1) NOT NULL DEFAULT 0");
}
// Equipment report "Others": items a barangay has that are not on the fixed checklist, stored as JSON
// [{"name": "...", "qty": 3, "remarks": "..."}] and shown to the DRRMC Admin with the rest of the report.
$other_items_column = $conn->query("SHOW COLUMNS FROM equipment_reports LIKE 'other_items'");
if ($other_items_column && $other_items_column->num_rows === 0) {
    $conn->query("ALTER TABLE equipment_reports ADD other_items TEXT NULL");
}
// Decodes a report's other_items safely; always returns a list of ['name' => string, 'qty' => int, 'remarks' => string].
function report_other_items($report){
    $decoded = json_decode((string)($report['other_items'] ?? ''), true);
    if (!is_array($decoded)) return [];
    $items = [];
    foreach ($decoded as $row) {
        if (!is_array($row) || trim((string)($row['name'] ?? '')) === '') continue;
        $items[] = ['name' => trim((string)$row['name']), 'qty' => max(0, (int)($row['qty'] ?? 0)), 'remarks' => trim((string)($row['remarks'] ?? ''))];
    }
    return $items;
}
// Automated restocking reminders: one open cycle per barangay until stock is optimal again.
$conn->query("CREATE TABLE IF NOT EXISTS restock_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay INT NOT NULL,
    first_sent_at DATETIME NOT NULL,
    last_sent_at DATETIME NOT NULL,
    send_count INT NOT NULL DEFAULT 1,
    resolved_at DATETIME NULL,
    INDEX idx_restock_open (barangay, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS app_state (
    k VARCHAR(60) PRIMARY KEY,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Legacy migration (old rule: one account per role). Now one Gmail = one active account, enforced by email_account_taken().
$email_unique = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'email' AND Non_unique = 0");
if ($email_unique && $email_unique->num_rows > 0) {
    $conn->query("ALTER TABLE users DROP INDEX email, ADD UNIQUE KEY uniq_email_role (email, role)");
}

$phone_column = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
if ($phone_column && $phone_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD phone VARCHAR(40) NULL AFTER address");
}

// Absolute URL for a page in this app, built from the current request (works whatever the folder is named).
function app_url($path = ''){
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

// Run the automatic restocking engine. $force = run now (after a report); otherwise throttled to once a minute.
function restock_engine_trigger($force = false){
    try {
        require_once __DIR__ . '/restock_engine.php';
        // A saved report ($force) also queues the barangay's ML demand forecast; it runs in the background tick.
        if ($force && !empty($_SESSION['user_id'])) {
            require_once __DIR__ . '/forecast_engine.php';
            forecast_queue_user((int)$_SESSION['user_id']);
        }
        return $force ? restock_engine_run() : restock_engine_tick();
    } catch (Throwable $e) {
        error_log('Restock engine: ' . get_class($e) . ' ' . $e->getMessage());
        return null;
    }
}

// Asset URL with a version taken from the file's modification time, so browsers never reuse a stale copy.
function asset_v($path){
    $file = __DIR__ . '/' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

// Looping background video + dark overlay for the sign-in pages (login, register, pending, choose account).
// Self-contained styles so it works on pages that don't load assets/style.css.
function auth_video_background(){
    if (!is_file(__DIR__ . '/assets/video.mp4')) return;
    echo '<style>'
       . '.auth-bg-video{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;z-index:-2;}'
       . '.auth-bg-overlay{position:fixed;inset:0;z-index:-1;background:linear-gradient(135deg,rgba(15,23,42,.72),rgba(6,78,59,.55));}'
       . 'html{background:#0f172a;}body.auth-bg{background:transparent !important;}'
       . '@media (prefers-reduced-motion: reduce){.auth-bg-video{display:none;}}'
       . '</style>' . "\n";
    echo '<video class="auth-bg-video" autoplay muted loop playsinline preload="auto" aria-hidden="true">'
       . '<source src="' . e(asset_v('assets/video.mp4')) . '" type="video/mp4"></video>' . "\n";
    echo '<div class="auth-bg-overlay" aria-hidden="true"></div>' . "\n";
    echo '<script>document.body.classList.add("auth-bg");</script>' . "\n";
}

function csrf_token(){
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_valid($token){
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function dashboard_for_role($role){
    return in_array(normalize_role($role), ['head_admin', 'admin'], true) ? 'admin_dashboard.php' : 'user_dashboard.php';
}

// Start an authenticated session for an approved user (used by Google SSO and the setup-mode password form).
function login_user_session(array $u, $method = 'google'){
    global $conn;
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_name'] = $u['fullname'];
    $_SESSION['user_barangay'] = $u['barangay'] ?? '';
    unset($_SESSION['google_identity'], $_SESSION['google_oauth_state'], $_SESSION['ad_popup_shown']); // ad_popup_shown: show the welcome ads again after this login

    $device = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 160) : 'Web browser';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $session_key = hash('sha256', session_id());
    $device_type = stripos($device, 'Mobile') !== false ? 'mobile' : (stripos($device, 'Tablet') !== false ? 'tablet' : 'desktop');
    $uid = (int)$u['id'];
    $session_stmt = $conn->prepare("INSERT INTO security_sessions (user_id, session_key, device, device_type, ip_address, location) VALUES (?, ?, ?, ?, ?, 'Caloocan, PH') ON DUPLICATE KEY UPDATE last_active=CURRENT_TIMESTAMP, revoked=0");
    if ($session_stmt) { $session_stmt->bind_param('issss', $uid, $session_key, $device, $device_type, $ip); $session_stmt->execute(); $session_stmt->close(); }
    $login_stmt = $conn->prepare("INSERT INTO security_login_activity (user_id, ip_address, location, device, status) VALUES (?, ?, 'Caloocan, PH', ?, 'Success')");
    if ($login_stmt) { $login_stmt->bind_param('iss', $uid, $ip, $device); $login_stmt->execute(); $login_stmt->close(); }
    audit_log('LOGIN', (string)($u['email'] ?? ''), 'Method: ' . $method);
    return dashboard_for_role($u['role']);
}

function is_logged_in(){
    return !empty($_SESSION['user_id']);
}

function require_login(){
    if(!is_logged_in()){
        header('Location: login.php'); exit;
    }
}

// Three tiers: head_admin (city governance), admin (DRRMC management), staff (barangay user).
// Legacy DB values: 'official' is the DRRMC admin tier, 'user' is barangay staff.
function normalize_role($r){
    $r = strtolower(trim((string)$r));
    if($r === '') return '';
    if($r === 'official') return 'admin';
    if($r === 'user') return 'staff';
    return $r;
}

// Record an action in tbl_audit_log. Never breaks the calling request.
// $actor_id = 0 records a system event with no user; $actor_role overrides the session's role (e.g. 'system').
function audit_log($action, $target = '', $details = '', $status = 'Success', $actor_id = null, $actor_label = null, $actor_role = null){
    global $conn;
    try {
        $uid = $actor_id !== null ? (int)$actor_id : (int)($_SESSION['user_id'] ?? 0);
        $uid = $uid > 0 ? $uid : null;
        $label = $actor_label ?? (string)($_SESSION['user_name'] ?? '');
        $role = $actor_role ?? normalize_role($_SESSION['user_role'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare('INSERT INTO tbl_audit_log (user_id, actor_label, actor_role, action, target, details, status, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('isssssss', $uid, $label, $role, $action, $target, $details, $status, $ip);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Audit failures must not block the user action.
    }
}

function current_user_role(){
    return normalize_role($_SESSION['user_role'] ?? '');
}

function is_role_in($allowed){
    $role = current_user_role();
    foreach ((array)$allowed as $a) {
        if(normalize_role($a) === $role) return true;
    }
    return false;
}

function require_role($allowed){
    if(!is_role_in($allowed)){
        header('Location: user_dashboard.php'); exit;
    }
}

function admin_return_page($raw = null){
    $page = basename((string)($raw ?? ($_SERVER['PHP_SELF'] ?? 'admin_reports.php')));
    $allowed = ['admin_dashboard.php', 'admin_reports.php', 'admin_approvals.php', 'inventory.php'];
    return in_array($page, $allowed, true) ? $page : 'admin_reports.php';
}

function admin_action_notice_html(){
    if (empty($_GET['msg'])) return '';
    $m = $_GET['msg'];
    $mail = !empty($_GET['mail']) ? ' Email: ' . e($_GET['mail']) . '.' : '';
    if ($m === 'approved') {
        return '<div class="notice" style="margin:12px 0;">User approved successfully.' . $mail . '</div>';
    }
    if ($m === 'rejected') {
        return '<div class="errors" style="margin:12px 0;">User rejected.' . $mail . '</div>';
    }
    return '';
}

function pending_registrations_html(){
    global $conn;
    $pending_users = [];
    $pend = $conn->query("SELECT id, fullname, email, barangay, position, verification_file, created_at FROM users WHERE status='pending' ORDER BY created_at ASC");
    if ($pend) {
        while ($p = $pend->fetch_assoc()) $pending_users[] = $p;
    }

    $return_to = admin_return_page();
    ob_start();
    if ($pending_users) {
        foreach ($pending_users as $p) {
            echo '<div class="sidebar-pending-item">';
            echo '<strong>'.e($p['fullname']).'</strong>';
            echo '<span>Brgy '.e($p['barangay']).' · '.e($p['position']).'</span>';
            if (!empty($p['verification_file'])) {
                echo '<a href="'.e($p['verification_file']).'" target="_blank">View verification</a>';
            }
            $pos = $p['position'] ?? '';
            $target_is_official = in_array($pos, ['Officer','Coordinator','DRRM Officer','Barangay Coordinator']);
            $can_act = false;
            if ($target_is_official) {
                $can_act = is_role_in(['head_admin']);
            } else {
                if (is_role_in(['head_admin'])) $can_act = true;
                if (is_role_in(['admin'])) {
                    $myb = $_SESSION['user_barangay'] ?? '';
                    if ($myb !== '' && $myb == $p['barangay']) $can_act = true;
                }
            }
            if ($can_act) {
                echo '<div class="sidebar-pending-actions">';
                echo '<form method="post" action="approve_user.php"><input type="hidden" name="id" value="'.(int)$p['id'].'"><input type="hidden" name="return_to" value="'.e($return_to).'"><button class="btn btn-small">Approve</button></form>';
                echo '<form method="post" action="reject_user.php"><input type="hidden" name="id" value="'.(int)$p['id'].'"><input type="hidden" name="return_to" value="'.e($return_to).'"><button class="btn btn-small btn-accent" style="background:#e74c3c;border-color:#e74c3c;">Reject</button></form>';
                echo '</div>';
            }
            echo '</div>';
        }
    } else {
        echo '<div class="sidebar-pending-empty">No pending registrations.</div>';
    }
    return ob_get_clean();
}

function render_pending_registrations(){
    echo '<div class="sidebar-pending">';
    echo '<h4>Pending Registrations</h4>';
    echo pending_registrations_html();
    echo '</div>';
}

// One barangay staff account per barangay: returns the account that already holds this barangay
// (approved, or also pending when $count_pending), or null. $exclude_id skips the account being checked.
function barangay_account_taken($barangay, $exclude_id = 0, $count_pending = true) {
    global $conn;
    $num = (string)(int)preg_replace('/\D+/', '', (string)$barangay);
    if ($num === '0') return null;
    $st = $conn->prepare("SELECT id, fullname, email, status FROM users WHERE role = 'user' AND barangay = ? AND id <> ? AND status IN ('approved'" . ($count_pending ? ", 'pending'" : '') . ") ORDER BY status = 'approved' DESC, id LIMIT 1");
    $ex = (int)$exclude_id;
    $st->bind_param('si', $num, $ex);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
// One Gmail = one active account: returns another approved account using this email
// (an account of any status when $any_status), or null.
function email_account_taken($email, $exclude_id = 0, $any_status = false) {
    global $conn;
    $st = $conn->prepare("SELECT id, fullname, role FROM users WHERE email = ? AND id <> ?" . ($any_status ? '' : " AND status = 'approved'") . " LIMIT 1");
    $em = (string)$email; $ex = (int)$exclude_id;
    $st->bind_param('si', $em, $ex);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function barangay_taken_message($barangay, array $holder) {
    return barangay_label((int)preg_replace('/\D+/', '', (string)$barangay)) . ' already has ' . ($holder['status'] === 'pending' ? 'a pending registration' : 'a registered staff account')
        . '. Only one account is allowed per barangay.';
}

?>
