<?php
require_once 'init.php';
if (!empty($_SESSION['user_id'])) {
    audit_log('LOGOUT', (string)($_SESSION['user_name'] ?? ''));
}
session_unset();
session_destroy();
header('Location: login.php'); exit;
