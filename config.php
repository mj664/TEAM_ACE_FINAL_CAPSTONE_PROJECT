<?php
// config.php - DB connection
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Philippine time for every date() in the app (XAMPP's php.ini defaults to Europe/Berlin, 6 hours off from the database clock).
date_default_timezone_set('Asia/Manila');

$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'capstone_db';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db_fail($message) {
    die('
    <h2>Database Connection Error</h2>
    <p>Make sure:</p>
    <ul>
        <li>MySQL is running (XAMPP Control Panel)</li>
        <li>Database <strong>capstone_db</strong> is created</li>
        <li>You imported <strong>db.sql</strong></li>
    </ul>
    <p>Error: ' . htmlspecialchars($message, ENT_QUOTES) . '</p>
    ');
}

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    db_fail($e->getMessage());
}

require_once __DIR__ . '/barangay_helpers.php';
require_once __DIR__ . '/mailer.php';

function e($v){ return htmlspecialchars($v, ENT_QUOTES); }

// Browser-tab icon: small round PNGs cut from assets/cdrrmd-logo.jpg (the full 1024px JPEG is too large for a tab icon).
// The ?v= stamp changes whenever the file changes, so browsers drop a cached old/missing icon.
function app_favicon(){
  $icon = function ($file) {
    $path = __DIR__ . '/assets/' . $file;
    return 'assets/' . $file . (is_file($path) ? '?v=' . filemtime($path) : '');
  };
  echo '<link rel="icon" type="image/png" sizes="32x32" href="' . $icon('favicon-32.png') . '">'."\n";
  echo '<link rel="icon" type="image/png" sizes="64x64" href="' . $icon('favicon-64.png') . '">'."\n";
  echo '<link rel="apple-touch-icon" sizes="180x180" href="' . $icon('favicon-180.png') . '">'."\n";
}

// Mail settings - configure for your environment if needed
$mail_from = 'no-reply@caloocan.local';
$mail_from_name = 'Caloocan DRRM';

function send_mail($to, $subject, $html_body){
    global $mail_from, $mail_from_name;
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: ' . $mail_from_name . ' <' . $mail_from . '>' . "\r\n";
    // Gmail SMTP when mail_config.php is set up (see mailer.php); otherwise PHP mail(), then the log file.
    $ok = false;
    if (mail_configured()) {
        $ok = mail_smtp_send($to, $subject, $html_body);
    } elseif (function_exists('mail')) {
        $ok = @mail($to, $subject, $html_body, $headers);
    }
    if (!$ok) {
        // Fallback: log the mail to a local file for testing on XAMPP
        $logdir = __DIR__ . '/logs';
        if (!is_dir($logdir)) @mkdir($logdir, 0755, true);
        $entry = "---\nTo: $to\nSubject: $subject\nHeaders: $headers\nBody:\n$html_body\n---\n";
        @file_put_contents($logdir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
    return $ok;
}

?>
