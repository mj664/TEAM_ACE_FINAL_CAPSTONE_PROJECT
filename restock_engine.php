<?php
/**
 * Automatic restocking reminder engine.
 *
 *  - A barangay whose latest equipment report is CRITICAL (a medical supply at 5 or fewer) gets an automated
 *    system message in its DRRM chat thread immediately.
 *  - While it stays below OPTIMAL (critical or warning) the same reminder is resent every 7 hours.
 *  - When the barangay reaches OPTIMAL the cycle is closed; a later shortage starts a new cycle.
 *
 * Runs from three places: right after a report is saved, from a once-a-minute web tick (any open dashboard),
 * and from the command line so Windows Task Scheduler / cron can drive it with nobody logged in:
 *      C:\xamppp\php\php.exe "C:\xamppp\htdocs\Capstone_(Updated)\restock_engine.php"
 */
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/south_caloocan_map.php';

const RESTOCK_INTERVAL_HOURS = 7;

// "Facemask, Gloves and Nasal Cannula" / "Facemask, Gloves, Nasal Cannula and 4 other items": names only, never a full list.
function restock_item_names(array $items, $max = 3) {
    $names = array_column($items, 'name');
    if (count($names) <= $max) {
        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : (string)($names[0] ?? 'some supplies');
    }
    $more = count($names) - $max;
    return implode(', ', array_slice($names, 0, $max)) . ' and ' . $more . ' other item' . ($more === 1 ? '' : 's');
}

// Written the way the DRRM Office would say it. The tone softens into a friendly follow-up, then a nudge that offers help.
function restock_message(array $status, $reminder_number) {
    $barangay = $status['label'];
    $critical = !empty($status['low_items']);
    $items = restock_item_names($critical ? $status['low_items'] : $status['warning_items']);
    $n = (int)$reminder_number;

    if (!$critical) {
        return "Hello, {$barangay}! Quick check-in from the DRRM Office. Most of your supplies are okay now, but {$items} could still use a top-up.\n\n"
            . "Once you've restocked, please update your equipment report so we can see it on our end. Thank you!\n\n- DRRM Office";
    }
    if ($n <= 1) {
        return "Good day, {$barangay}! This is the DRRM Office. We noticed some of your medical supplies are running low: {$items}.\n\n"
            . "When you get a chance, could you restock and send us an updated equipment report? It helps us keep the map accurate and see where we can help.\n\n"
            . "Thank you for all that you do!\n\n- DRRM Office";
    }
    if ($n === 2) {
        return "Hello again, {$barangay}. Just a gentle follow-up from the DRRM Office. Our records still show low stock for {$items}.\n\n"
            . "Please restock when you can and update your equipment report. Thank you!\n\n- DRRM Office";
    }
    return "Hi {$barangay}, following up once more from the DRRM Office. We're still seeing low stock on {$items}.\n\n"
        . "If something is getting in the way of restocking, just reply here and we'll do our best to help. Once you're restocked, please send an updated equipment report.\n\n"
        . "Thank you po!\n\n- DRRM Office";
}

// Approved staff accounts of one barangay: the members of that barangay's DRRM chat thread.
function restock_recipients($barangay_number) {
    global $conn;
    $number = (string)(int)$barangay_number;
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'user' AND status = 'approved' AND barangay = ?");
    $stmt->bind_param('s', $number);
    $stmt->execute();
    $ids = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $ids[] = (int)$row['id'];
    $stmt->close();
    return $ids;
}

// The DRRMC staff account the reminders are sent from: the first approved DRRMC Admin. Null when none exists.
function restock_sender_id() {
    global $conn;
    $row = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'official') AND status = 'approved' ORDER BY id ASC LIMIT 1")->fetch_assoc();
    return $row ? (int)$row['id'] : null;
}

// Sends one reminder to every staff account of the barangay, from the DRRMC staff account. Returns how many were sent.
function restock_dispatch(array $status, $open_row) {
    global $conn;
    $recipients = restock_recipients($status['number']);
    if (!$recipients) return 0;

    $number = $open_row ? (int)$open_row['send_count'] + 1 : 1;
    $text = restock_message($status, $number);
    $sender_id = restock_sender_id();
    if ($sender_id !== null) {
        // Appears in the barangay's chat as a message from the DRRM Office, and in the Admin's chat as one they sent.
        $insert = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message, auto_sent) VALUES (?, ?, 'admin', ?, 1)");
    } else {
        $insert = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message, auto_sent) VALUES (?, NULL, 'system', ?, 1)");
    }
    $sent = 0;
    foreach ($recipients as $user_id) {
        if ($sender_id !== null) $insert->bind_param('iis', $user_id, $sender_id, $text);
        else $insert->bind_param('is', $user_id, $text);
        $insert->execute();
        $sent++;
    }
    $insert->close();

    $barangay = (int)$status['number'];
    if ($open_row) {
        $update = $conn->prepare('UPDATE restock_reminders SET last_sent_at = NOW(), send_count = send_count + 1 WHERE id = ?');
        $update->bind_param('i', $open_row['id']);
        $update->execute();
        $update->close();
    } else {
        $create = $conn->prepare('INSERT INTO restock_reminders (barangay, first_sent_at, last_sent_at, send_count) VALUES (?, NOW(), NOW(), 1)');
        $create->bind_param('i', $barangay);
        $create->execute();
        $create->close();
    }
    audit_log('AUTO_RESTOCK_REMINDER', $status['label'], 'Reminder #' . $number . ' sent to ' . $sent . ' staff account(s) on behalf of ' . ($sender_id !== null ? 'DRRMC staff (user #' . $sender_id . ')' : 'the DRRM Office'), 'Success', 0, 'AI Restocking Engine', 'system');
    return $sent;
}

// Evaluates every barangay once. Safe to call from several places at once: a database lock lets only one run at a time.
function restock_engine_run() {
    global $conn;
    $lock = $conn->query("SELECT GET_LOCK('drrmc_restock_engine', 0) AS got")->fetch_assoc();
    if (empty($lock['got'])) return ['skipped' => true];

    $summary = ['skipped' => false, 'started' => 0, 'repeated' => 0, 'resolved' => 0, 'messages' => 0];
    try {
        $open = [];
        $open_result = $conn->query('SELECT id, barangay, send_count, (last_sent_at <= NOW() - INTERVAL ' . RESTOCK_INTERVAL_HOURS . ' HOUR) AS due FROM restock_reminders WHERE resolved_at IS NULL');
        while ($row = $open_result->fetch_assoc()) $open[(int)$row['barangay']] = $row;

        foreach (build_barangay_stock_status($conn) as $status) {
            if (empty($status['has_report'])) continue;
            $number = (int)$status['number'];
            $row = $open[$number] ?? null;
            $level = $status['level'] ?? 'idle';

            if ($level === 'critical') {
                if (!$row) {
                    $sent = restock_dispatch($status, null);
                    if ($sent) { $summary['started']++; $summary['messages'] += $sent; }
                } elseif (!empty($row['due'])) {
                    $sent = restock_dispatch($status, $row);
                    if ($sent) { $summary['repeated']++; $summary['messages'] += $sent; }
                }
            } elseif ($level === 'warning') {
                // Still below optimal: an open cycle keeps repeating, but a warning alone never starts one.
                if ($row && !empty($row['due'])) {
                    $sent = restock_dispatch($status, $row);
                    if ($sent) { $summary['repeated']++; $summary['messages'] += $sent; }
                }
            } elseif ($level === 'optimal' && $row) {
                $close = $conn->prepare('UPDATE restock_reminders SET resolved_at = NOW() WHERE id = ?');
                $close->bind_param('i', $row['id']);
                $close->execute();
                $close->close();
                audit_log('AUTO_RESTOCK_RESOLVED', $status['label'], 'Stock back to optimal after ' . (int)$row['send_count'] . ' reminder(s)', 'Success', 0, 'AI Restocking Engine', 'system');
                $summary['resolved']++;
            }
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('drrmc_restock_engine')");
    }
    return $summary;
}

// Web entry point: at most one evaluation per $min_seconds no matter how many pages call it.
function restock_engine_tick($min_seconds = 60) {
    global $conn;
    $conn->query("INSERT IGNORE INTO app_state (k, updated_at) VALUES ('restock_last_run', '2000-01-01 00:00:00')");
    $claim = $conn->prepare("UPDATE app_state SET updated_at = NOW() WHERE k = 'restock_last_run' AND updated_at <= NOW() - INTERVAL ? SECOND");
    $claim->bind_param('i', $min_seconds);
    $claim->execute();
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    return $claimed ? restock_engine_run() : null;
}

// Command line: php restock_engine.php
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode(restock_engine_run()) . PHP_EOL;
}
