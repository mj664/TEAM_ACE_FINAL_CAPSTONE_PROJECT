<?php
// AI label scan for expiry tracking: reads the expiry date (and lot no.) from a photo of a medical supply's box or label.
require_once 'init.php';
require_once __DIR__ . '/expiry_lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function exp_reply(array $a, $code = 200) { http_response_code($code); echo json_encode($a); exit; }

if (!is_logged_in()) exp_reply(['ok' => false, 'message' => 'Please sign in again.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? '')) exp_reply(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419);
$f = $_FILES['label'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) exp_reply(['ok' => false, 'message' => 'Choose a photo of the label.']);
if ($f['size'] > 8 * 1024 * 1024) exp_reply(['ok' => false, 'message' => 'The photo is too large (8 MB max).']);
$info = @getimagesize($f['tmp_name']);
if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) exp_reply(['ok' => false, 'message' => 'Use a JPG, PNG or WEBP photo.']);

// Each scan is a paid AI call: limit per session.
$_SESSION['exp_scans'] = (int)($_SESSION['exp_scans'] ?? 0) + 1;
if ($_SESSION['exp_scans'] > 30) exp_reply(['ok' => false, 'message' => 'Scan limit reached for now. Please type the expiry date.']);
session_write_close(); // do not hold the session lock during the AI call
set_time_limit(60);

$r = exp_scan_label($f['tmp_name'], $info['mime']);
audit_log('SUPPLY_LABEL_SCANNED', 'expiry scan', $r['ok'] ? 'Read expiry ' . $r['expiry'] . ($r['lot'] ? ', lot ' . $r['lot'] : '') : 'Not read', $r['ok'] ? 'Success' : 'Failed');
exp_reply($r);
