<?php
require_once 'init.php';
require_once 'google_auth.php';

function google_fail($code, $detail = '') {
    audit_log('GOOGLE_LOGIN_FAILED', $code, $detail, 'Failed');
    header('Location: login.php?err=' . urlencode($code));
    exit;
}

if (!google_configured()) google_fail('not_configured');
if (isset($_GET['error'])) google_fail('google_cancelled', (string)$_GET['error']);

$expected_state = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);
if ($expected_state === '' || !hash_equals($expected_state, (string)($_GET['state'] ?? ''))) google_fail('state');

$code = (string)($_GET['code'] ?? '');
if ($code === '') google_fail('google_failed', 'Missing authorization code');

$config = google_config();
$token = google_http('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'redirect_uri'  => $config['redirect_uri'],
    'grant_type'    => 'authorization_code',
]);
$access_token = $token['json']['access_token'] ?? '';
if ($token['code'] !== 200 || $access_token === '') {
    google_fail('google_failed', 'Token exchange failed: ' . ($token['json']['error'] ?? $token['error'] ?: 'HTTP ' . $token['code']));
}

$profile = google_http('https://openidconnect.googleapis.com/v1/userinfo', null, ['Authorization: Bearer ' . $access_token]);
$info = $profile['json'] ?? [];
$email = strtolower(trim((string)($info['email'] ?? '')));
if ($profile['code'] !== 200 || $email === '') google_fail('google_failed', 'Profile lookup failed');
if (empty($info['email_verified'])) google_fail('unverified', $email);

// Verified Google identity. Routing (dashboard / account chooser / pending / registration) is shared code.
header('Location: ' . google_resolve_login([
    'email'   => $email,
    'name'    => (string)($info['name'] ?? ''),
    'picture' => (string)($info['picture'] ?? ''),
]));
exit;
