<?php
require_once 'init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_logged_in() || !is_role_in(['head_admin'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$rows = [];
$result = $conn->query("SELECT id, fullname, email, position, role, barangay, phone, address, verification_file, created_at FROM users WHERE status = 'pending' ORDER BY created_at ASC, id ASC");
if ($result) {
    while ($u = $result->fetch_assoc()) {
        $is_drrmc = strtolower((string)$u['role']) === 'official' || stripos((string)$u['position'], 'DRRMC') !== false;
        $is_barangay = !$is_drrmc && stripos((string)$u['position'], 'Barangay') !== false;
        $barangay_number = preg_replace('/\D+/', '', (string)$u['barangay']);
        $rows[] = [
            'id'              => (int)$u['id'],
            'name'            => $u['fullname'],
            'email'           => $u['email'],
            'role_label'      => $is_drrmc ? 'DRRMC Staff' : ($is_barangay ? 'Barangay Staff' : 'Not detected - check the ID'),
            'default_role'    => $is_drrmc ? 'admin' : 'user',
            // Role read from the ID (position/header on the card) is fixed; the Head Admin only picks it when it was not detected.
            'role_locked'     => $is_drrmc || $is_barangay,
            'barangay'        => $is_drrmc ? 'City-level (DRRMC)' : ($barangay_number !== '' ? barangay_label($u['barangay']) : 'Not detected - check the ID'),
            'barangay_number' => $is_drrmc ? '' : $barangay_number,
            'phone'           => $u['phone'] ?: 'Not provided',
            'address'         => $u['address'] ?: 'Not detected',
            'id_url'          => $u['verification_file'] !== '' && $u['verification_file'] !== null ? 'view_id.php?id=' . (int)$u['id'] : '',
            'submitted'       => date('M j, Y g:i A', strtotime($u['created_at'])),
        ];
    }
}
echo json_encode($rows);
