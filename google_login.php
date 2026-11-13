<?php
require_once 'init.php';
require_once 'google_auth.php';

if (!google_configured()) {
    header('Location: login.php?err=not_configured');
    exit;
}

$config = google_config();
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => $config['client_id'],
    'redirect_uri'  => $config['redirect_uri'],
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
    'access_type'   => 'online',
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
