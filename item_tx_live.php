<?php
// JSON: the current stock of one item in one barangay (Main Inventory = latest equipment report).
require_once 'init.php';
require_login();
require_once 'item_tx_lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$b = (int)($_GET['b'] ?? 0);
if (!is_role_in(['head_admin', 'admin', 'official'])) {
    // Barangay staff: only their own barangay.
    $st = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
    $uid = (int)$_SESSION['user_id'];
    $st->bind_param('i', $uid);
    $st->execute();
    $mine = (int)preg_replace('/\D+/', '', (string)($st->get_result()->fetch_assoc()['barangay'] ?? ''));
    $st->close();
    if ($b !== $mine) { echo json_encode(['ok' => false, 'error' => 'Not available.']); exit; }
}
$s = item_tx_live($b, (string)($_GET['item'] ?? ''));
echo json_encode($s ? ['ok' => true, 'status' => $s] : ['ok' => false, 'error' => 'Unknown item.']);
