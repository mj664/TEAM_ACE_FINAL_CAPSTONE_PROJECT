<?php
/**
 * Google Sign-In (OAuth 2.0 authorization-code flow) helpers.
 * Credentials live in google_config.php (see google_config.sample.php); that file is not committed.
 */

function google_config(){
    static $config = null;
    if ($config !== null) return $config;
    $file = __DIR__ . '/google_config.php';
    $loaded = is_file($file) ? include $file : [];
    $config = (is_array($loaded) ? $loaded : []) + ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''];
    if ($config['redirect_uri'] === '') $config['redirect_uri'] = app_url('google_callback.php');
    return $config;
}

function google_configured(){
    $c = google_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== ''
        && stripos($c['client_id'], 'YOUR_') === false && stripos($c['client_secret'], 'YOUR_') === false;
}

// Minimal HTTPS client. Returns ['code' => int, 'json' => array|null, 'error' => string].
function google_http($url, array $post = null, array $headers = []){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $result = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => null, 'error' => curl_error($ch)];
    curl_close($ch);
    if (is_string($body)) $result['json'] = json_decode($body, true);
    return $result;
}

/**
 * Decide what a verified Google email may do. One Gmail = one active account (no account chooser).
 * Returns ['action' => login|choose|pending|rejected|register, 'users' => [...]].
 */
function google_route_for_email($email){
    global $conn;
    $stmt = $conn->prepare('SELECT id, email, role, status, fullname, barangay, position, created_at FROM users WHERE email = ? ORDER BY id ASC');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $with_status = function ($status) use ($rows) {
        return array_values(array_filter($rows, function ($r) use ($status) { return $r['status'] === $status; }));
    };
    $approved = $with_status('approved');
    if ($approved) {
        // Safety net for old data: if several are still active, sign in to the highest role only.
        $rank = ['head_admin' => 0, 'admin' => 1];
        usort($approved, function ($a, $b) use ($rank) { return ($rank[$a['role']] ?? 9) <=> ($rank[$b['role']] ?? 9); });
        return ['action' => 'login', 'users' => [$approved[0]]];
    }
    $pending = $with_status('pending');
    if ($pending) return ['action' => 'pending', 'users' => $pending];
    if ($rows) return ['action' => 'rejected', 'users' => $rows];
    return ['action' => 'register', 'users' => []];
}

/**
 * Apply a verified Google identity: remember it, then return the page the user should go to.
 * Signs the user in when exactly one approved account matches. $audit=false for polling callers.
 */
function google_resolve_login(array $identity, $audit = true){
    global $conn;
    $_SESSION['google_identity'] = ['email' => $identity['email'], 'name' => $identity['name'] ?? '', 'picture' => $identity['picture'] ?? ''];
    $route = google_route_for_email($identity['email']);
    switch ($route['action']) {
        case 'login':
            return login_user_session($route['users'][0], 'google');
        case 'pending':
            if ($audit) audit_log('GOOGLE_PENDING', $identity['email'], 'Awaiting Head Admin approval', 'Success', (int)$route['users'][0]['id'], $identity['email']);
            return 'pending_approval.php';
        case 'rejected':
            unset($_SESSION['google_identity']);
            if ($audit) audit_log('GOOGLE_LOGIN_FAILED', 'rejected', $identity['email'], 'Failed', null, $identity['email']);
            return 'login.php?err=rejected';
        default:
            if ($audit) audit_log('GOOGLE_UNREGISTERED', $identity['email'], 'Redirected to registration', 'Success', null, $identity['email']);
            return 'register.php';
    }
}
