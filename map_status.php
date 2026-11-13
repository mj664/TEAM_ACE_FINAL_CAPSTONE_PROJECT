<?php
require_once 'init.php';
require_once __DIR__ . '/south_caloocan_map.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['barangays' => []]);
    exit;
}

restock_engine_trigger(false); // keeps automatic reminders running while any map is open
echo json_encode(['barangays' => build_barangay_stock_status($conn), 'generated_at' => date('c')], JSON_UNESCAPED_UNICODE);
