<?php
require_once 'init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    echo json_encode(['unread' => 0]);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$session_role = current_user_role();

if (in_array($session_role, ['head_admin', 'admin'], true)) {
    // DRRMC side: unread messages written by barangay staff.
    $unread = (int)$conn->query("SELECT COUNT(*) AS total FROM contact_messages WHERE sender_role = 'user' AND is_read = 0")->fetch_assoc()['total'];
} else {
    // Barangay side: unread replies from the DRRM office and automated AI reminders.
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM contact_messages WHERE user_id = ? AND sender_role IN ('admin', 'system') AND is_read = 0");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $unread = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
}

session_write_close(); // the engines below may call the weather service / AI: never hold the session lock meanwhile

// Keeps the automatic reminders running while anyone has a page open (throttled to once a minute).
restock_engine_trigger(false);
// Delivery reminders for approved resource requests (day before / delivery day), throttled to once a minute.
try { require_once __DIR__ . '/resource_request_lib.php'; rr_delivery_tick(); require_once __DIR__ . '/expiry_lib.php'; exp_tick(); } catch (Throwable $e) { error_log('Delivery reminders: ' . $e->getMessage()); }

// Weather-based restocking alerts: the engine checks the weather every 30 minutes and only calls the AI when the threat rises.
$weather_alert = null;
try {
    require_once __DIR__ . '/weather_engine.php';
    weather_engine_tick();
    // ML demand forecast: refreshes barangays that just submitted a report (and all of them once a day).
    require_once __DIR__ . '/forecast_engine.php';
    forecast_tick();
    if (!in_array($session_role, ['head_admin', 'admin', 'official'], true)) {
        $me = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
        $me->bind_param('i', $user_id);
        $me->execute();
        $my_barangay = (string)($me->get_result()->fetch_assoc()['barangay'] ?? '');
        $me->close();
        $weather_alert = weather_alert_for_user($user_id, $my_barangay);
    }
} catch (Throwable $e) {
    error_log('Weather engine: ' . get_class($e) . ' ' . $e->getMessage());
}

echo json_encode(['unread' => $unread, 'weather_alert' => $weather_alert]);
