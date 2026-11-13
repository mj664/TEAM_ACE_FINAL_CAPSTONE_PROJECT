<?php
if (basename((string)($_SERVER["SCRIPT_FILENAME"] ?? "")) === basename(__FILE__)) { http_response_code(404); exit; } // only included by other pages
/**
 * Resource requests: the item list a barangay can request, and reading a saved request back into rows.
 * A request is saved as text ("16 Portable Oxygen, 20 First Aid Kit, Other: 20 sandbags"); rr_parse_items()
 * turns it into the same sections as the equipment report so the DRRMC Admin sees (and prints) a table.
 */

// Same items as the equipment report, so a request can only name things the barangay actually tracks.
function rr_item_catalog() {
    return [
        'Tools & Equipment' => [
            'portable_oxygen' => 'Portable Oxygen', 'stretcher_scoop' => 'Stretcher / Scoop', 'spider_strap' => 'Spider Strap',
            'bp_automatic' => 'BP Automatic / Manual', 'pulse_oximeter' => 'Pulse Oximeter', 'cervical_collar' => 'Cervical Collar',
            'first_aid_kit' => 'First Aid Kit', 'stretcher' => 'Stretcher', 'backboard' => 'Backboard', 'resuscitator' => 'Resuscitator',
            'defibrillator' => 'Defibrillator (AED)', 'aspirator' => 'Aspirator / Suction Device', 'flashlight' => 'Flashlight / Search Light',
            'rope' => 'Rope / Cord', 'carabiner' => 'Carabiner / Hooks', 'harness' => 'Safety Harness', 'life_jacket' => 'Life Jacket',
            'helmet' => 'Safety Helmet', 'impact_resistant_boots' => 'Impact Resistant Boots', 'hazmat_suit' => 'HAZMAT Suit / PPE',
            't_bandage' => 'T-Bandage', 's_splint' => 'S Splint', 'w_splint' => 'W Splint', 'umbrella' => 'Umbrella',
            'cold_hot_compress' => 'Cold / Hot Compress',
        ],
        'Medical Supplies' => [
            'facemask' => 'Facemask', 'elastic_bandage' => 'Elastic Bandage', 'gloves' => 'Gloves', 'betadine_dropper' => 'Betadine Dropper',
            'surgical_tape' => 'Surgical Tape', 'gauze_pad' => '4x4 Gauze Pad', 'nasal_cannula' => 'Nasal Cannula', 'oxygen_mask' => 'Oxygen Mask',
        ],
    ];
}

// Sections in the order of the printed report. (Vehicles are not part of the system: an old request that named
// one shows it under Other items.)
function rr_sections() {
    $c = rr_item_catalog();
    return [
        'NAME OF MEDICAL EQUIPMENT' => $c['Tools & Equipment'],
        'NAME OF MEDICAL SUPPLY' => $c['Medical Supplies'],
    ];
}

/**
 * Saved text -> ['sections' => [title => [[key, label, qty], ...]], 'other' => [[name, qty|null], ...], 'total' => units].
 * Items are listed in the report's order. An "Other" entry that names a known item (e.g. "20 Facemask" typed under Others in a
 * spreadsheet) is placed in its section; anything unknown stays under Other items.
 */
function rr_parse_items($text) {
    $text = trim((string)$text);
    $other_text = '';
    if (($p = strpos($text, 'Other: ')) !== false && ($p === 0 || substr($text, $p - 2, 2) === ', ')) {
        $other_text = trim(substr($text, $p + 7));
        $text = rtrim(substr($text, 0, $p), ', ');
    }
    $by_label = [];
    foreach (rr_sections() as $title => $items) foreach ($items as $key => $label) {
        $by_label[strtolower($label)] = [$title, $key, $label];
    }
    $found = []; $other = [];
    $take = function ($chunk) use (&$found, &$other, $by_label) {
        $chunk = trim($chunk);
        if ($chunk === '') return;
        if (preg_match('/^(\d+)\s+(.+)$/', $chunk, $m)) {
            $name = trim($m[2]); $qty = (int)$m[1];
            if (preg_match('/^(ambulance|fire\s*truck)(\s+count)?$/i', $name)) return; // vehicles are not part of the system
            $k = strtolower(preg_replace('/\s+count$/i', '', $name));
            if (isset($by_label[$k])) { [$t, $key, $label] = $by_label[$k]; $found[$key] = ($found[$key] ?? 0) + $qty; return; }
            $other[] = [$name, $qty];
        } else {
            $other[] = [$chunk, null];
        }
    };
    foreach ($text === '' ? [] : explode(', ', $text) as $chunk) $take($chunk);
    foreach ($other_text === '' ? [] : preg_split('/\s*[,;]\s*/', $other_text) as $chunk) $take($chunk);
    $sections = []; $total = 0;
    foreach (rr_sections() as $title => $items) foreach ($items as $key => $label) {
        if (!isset($found[$key])) continue;
        $sections[$title][] = [$key, $label, $found[$key]];
        $total += $found[$key];
    }
    foreach ($other as $o) $total += (int)$o[1];
    return ['sections' => $sections, 'other' => $other, 'total' => $total];
}

// Compact item table for the admin's request cards (escaped HTML).
function rr_items_table_html($text) {
    $p = rr_parse_items($text);
    $short = ['NAME OF MEDICAL EQUIPMENT' => 'Tools & Equipment', 'NAME OF MEDICAL SUPPLY' => 'Medical Supplies'];
    $h = '<table class="rr-items"><thead><tr><th>Item</th><th>Qty</th></tr></thead><tbody>';
    foreach ($p['sections'] as $title => $rows) {
        $h .= '<tr class="rr-sec"><td colspan="2">' . e($short[$title] ?? $title) . '</td></tr>';
        foreach ($rows as [$key, $label, $qty]) $h .= '<tr><td>' . e($label) . '</td><td>' . (int)$qty . '</td></tr>';
    }
    if ($p['other']) {
        $h .= '<tr class="rr-sec"><td colspan="2">Other items</td></tr>';
        foreach ($p['other'] as [$name, $qty]) $h .= '<tr><td>' . e($name) . '</td><td>' . ($qty === null ? '&ndash;' : (int)$qty) . '</td></tr>';
    }
    $h .= '</tbody><tfoot><tr><td>Total units</td><td>' . (int)$p['total'] . '</td></tr></tfoot></table>';
    return $h;
}

/* ---------------------------------------------------------------------------------------------------------------
 * Delivery of approved requests.
 *  1. A new request is acknowledged right away (automatic message: "received, waiting for review").
 *  2. Approving sets the delivery date, time and place (e.g. the barangay hall); the barangay is messaged.
 *  3. Automatic reminders: the day before and on the delivery day (rr_delivery_tick, from the page poll).
 *  4. The DRRMC Admin marks it "out for delivery" and "delivered" (each one messages the barangay),
 *     or reschedules it (reminders start over).
 * ------------------------------------------------------------------------------------------------------------- */
function rr_delivery_install() {
    global $conn;
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = [
        'acknowledged_at' => 'DATETIME NULL DEFAULT NULL',
        'delivery_at' => 'DATETIME NULL DEFAULT NULL',
        'delivery_location' => 'VARCHAR(255) NULL DEFAULT NULL',
        'delivery_status' => "ENUM('scheduled','out','delivered') NULL DEFAULT NULL",
        'delivered_at' => 'DATETIME NULL DEFAULT NULL',
        'remind_day_before_at' => 'DATETIME NULL DEFAULT NULL',
        'remind_today_at' => 'DATETIME NULL DEFAULT NULL',
        'received_at' => 'DATETIME NULL DEFAULT NULL',      // the barangay confirmed it received the items
        'received_units' => 'INT NULL DEFAULT NULL',         // units added to its Main Inventory on receipt
        'proof_message_id' => 'INT NULL DEFAULT NULL',       // the barangay message holding the proof-of-delivery photo
        'preferred_method' => "ENUM('delivery','pickup') NULL DEFAULT NULL", // what the barangay asked for
        'fulfillment' => "ENUM('delivery','pickup') NULL DEFAULT NULL",      // what the DRRMC Admin decided
        'method_reason' => 'VARCHAR(255) NULL DEFAULT NULL', // why pick-up when the barangay asked for delivery
        'pickup_end' => 'DATETIME NULL DEFAULT NULL',        // end of the pick-up window (start = delivery_at)
        'claimed_by_name' => 'VARCHAR(150) NULL DEFAULT NULL', // who picked the items up
        'claimed_rep' => 'TINYINT(1) NOT NULL DEFAULT 0',    // 1 = a representative with an authorization letter
        'released_by' => 'INT NULL DEFAULT NULL',            // the DRRMC staff who handed the items over
        'missed_at' => 'DATETIME NULL DEFAULT NULL',         // when the current pick-up window passed unclaimed
        'missed_count' => 'INT NOT NULL DEFAULT 0',          // unexcused missed pick-ups (cancelling is allowed at RR_MAX_MISSES)
        'missed_total' => 'INT NOT NULL DEFAULT 0',          // all missed pick-ups, excused or not
        'miss_excused' => 'TINYINT(1) NOT NULL DEFAULT 0',   // the current miss was excused by the DRRMC Admin
        'resched_date' => 'DATE NULL DEFAULT NULL',          // new pick-up date asked by the barangay after a miss
        'resched_reason' => 'VARCHAR(255) NULL DEFAULT NULL',
        'resched_note' => 'VARCHAR(255) NULL DEFAULT NULL',
        'resched_at' => 'DATETIME NULL DEFAULT NULL',
        'pickup_history' => 'TEXT NULL',                     // one line per event (missed, excused, rescheduled, cancelled)
        'overdue_at' => 'DATETIME NULL DEFAULT NULL',        // a delivery whose day passed without being delivered
        'overdue_total' => 'INT NOT NULL DEFAULT 0',         // how many times the delivery did not arrive on its date
    ];
    // Two more delivery states for pick-ups: missed (window passed unclaimed) and cancelled (unclaimed, items back to city stock).
    $t = $conn->query("SHOW COLUMNS FROM resource_requests LIKE 'delivery_status'")->fetch_assoc();
    if ($t && strpos($t['Type'], 'missed') === false) $conn->query("ALTER TABLE resource_requests MODIFY delivery_status ENUM('scheduled','out','delivered','missed','cancelled') NULL DEFAULT NULL");
    foreach ($cols as $name => $def) {
        $r = $conn->query("SHOW COLUMNS FROM resource_requests LIKE '$name'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE resource_requests ADD `$name` $def");
    }
}
rr_delivery_install();

// The DRRMC staff account automatic messages are sent from (same rule as the restock reminders).
function rr_office_sender() {
    global $conn;
    $row = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'official') AND status = 'approved' ORDER BY id ASC LIMIT 1")->fetch_assoc();
    return $row ? (int)$row['id'] : null;
}

// Puts a message in the barangay staff member's Messages thread. $sender_id null = the office account.
function rr_message($user_id, $text, $sender_id = null, $auto = 1) {
    global $conn;
    $sender_id = $sender_id ?: rr_office_sender();
    $uid = (int)$user_id; $auto = (int)$auto;
    if ($sender_id) {
        $st = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message, auto_sent) VALUES (?, ?, 'admin', ?, ?)");
        $st->bind_param('iisi', $uid, $sender_id, $text, $auto);
    } else {
        $st = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message, auto_sent) VALUES (?, NULL, 'system', ?, ?)");
        $st->bind_param('isi', $uid, $text, $auto);
    }
    $ok = $st->execute();
    $st->close();
    return $ok;
}

// "Friday, October 16, 2026 at 9:00 AM - Barangay 12 Hall"
function rr_delivery_when($at) { return date('l, F j, Y \a\t g:i A', strtotime($at)); }
function rr_is_pickup(array $r) { return ($r['fulfillment'] ?? '') === 'pickup'; }
function rr_method_label(array $r) { return rr_is_pickup($r) ? 'Pick-up' : 'Delivery'; }
// Pick-up window: "9:00 AM - 4:00 PM"
function rr_pickup_window(array $r) { return date('g:i A', strtotime($r['delivery_at'])) . (!empty($r['pickup_end']) ? ' - ' . date('g:i A', strtotime($r['pickup_end'])) : ''); }
// "Thursday, October 16, 2026 at 9:00 AM - Barangay 12 Hall" / "Thursday, October 16, 2026, 9:00 AM - 4:00 PM - Caloocan City DRRM Office"
function rr_delivery_line(array $r) {
    if (rr_is_pickup($r)) return date('l, F j, Y', strtotime($r['delivery_at'])) . ', ' . rr_pickup_window($r) . ' - ' . $r['delivery_location'];
    return rr_delivery_when($r['delivery_at']) . ' - ' . $r['delivery_location'];
}
function rr_pickup_place() { return 'Caloocan City DRRM Office'; }
// Reasons the DRRMC Admin can give when a barangay asked for delivery but has to pick the items up.
function rr_pickup_reasons() {
    return ['No available vehicle on that date', 'Too many or too large items to deliver', 'Urgent: faster if the barangay picks it up',
        'Road not passable (flood or blocked area)', 'Not enough staff for delivery'];
}
// Reasons the DRRM Office gives when a delivery did not arrive on its date and is moved to a new one.
function rr_delay_reasons() {
    return ['Bad weather / typhoon / flooding', 'No available vehicle or driver', 'Items not yet available (waiting for city stock)',
        'Road closed or not passable', 'Emergency response took priority'];
}
// A delivery (not a pick-up) whose day passed without the items being delivered or received.
function rr_is_overdue(array $r) {
    return !rr_is_pickup($r) && !empty($r['overdue_at']) && empty($r['received_at']) && in_array($r['delivery_status'] ?? '', ['scheduled', 'out'], true);
}
// "Overdue 1 day" / "Overdue 3 days" (counted from the delivery date).
function rr_overdue_text(array $r) {
    $days = max(1, (int)round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($r['delivery_at'])))) / 86400));
    return 'Overdue ' . $days . ' day' . ($days === 1 ? '' : 's');
}
function rr_delivery_status_label($s) { return ['scheduled' => 'Scheduled', 'out' => 'Out for delivery', 'delivered' => 'Delivered'][$s] ?? 'Not scheduled'; }
// "Today", "Tomorrow", "In 3 days", "2 days ago"
function rr_delivery_countdown($at) {
    $days = (int)round((strtotime(date('Y-m-d', strtotime($at))) - strtotime(date('Y-m-d'))) / 86400);
    if ($days === 0) return 'Today';
    if ($days === 1) return 'Tomorrow';
    if ($days > 1) return 'In ' . $days . ' days';
    return abs($days) . ' day' . ($days === -1 ? '' : 's') . ' ago';
}
// Default delivery place: the requesting barangay's hall.
function rr_default_location($barangay) { return barangay_label((int)preg_replace('/\D+/', '', (string)$barangay)) . ' Hall'; }

// 1. Automatic acknowledgement when a barangay sends a request.
function rr_acknowledge($request_id) {
    global $conn;
    $id = (int)$request_id;
    $r = $conn->query("SELECT id, user_id, request_type, acknowledged_at FROM resource_requests WHERE id = $id")->fetch_assoc();
    if (!$r || $r['acknowledged_at']) return;
    $text = "📥 Request received: your resource request #$id (" . $r['request_type'] . ") reached the DRRM Office.\n\n"
        . "It is now waiting for review. You will get a message here once it is approved, with the delivery date, time and place.\n\n📄 Your request form (ready to print / save as PDF): " . rr_form_link($id) . "\n\n- DRRM Office";
    if (rr_message($r['user_id'], $text)) $conn->query("UPDATE resource_requests SET acknowledged_at = NOW() WHERE id = $id");
}

// 3. Reminders before delivery. Throttled to once a minute; safe to call on every page poll.
function rr_delivery_tick() {
    global $conn;
    $conn->query("INSERT IGNORE INTO app_state (k, updated_at) VALUES ('delivery_last_run', '2000-01-01')");
    $claim = $conn->query("UPDATE app_state SET updated_at = NOW() WHERE k = 'delivery_last_run' AND updated_at <= NOW() - INTERVAL 60 SECOND");
    if (!$claim || $conn->affected_rows !== 1) return 0;
    $sent = 0;
    // Day before (only when it was approved before today, so it never repeats the approval message).
    $rows = $conn->query("SELECT * FROM resource_requests WHERE status = 'approved' AND delivery_status = 'scheduled' AND remind_day_before_at IS NULL
        AND DATE(delivery_at) = CURDATE() + INTERVAL 1 DAY AND DATE(reviewed_at) < CURDATE()")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $text = rr_is_pickup($r)
            ? "🔔 Pick-up reminder: the items for your resource request #" . $r['id'] . " (" . $r['request_type'] . ") are ready for pick-up TOMORROW.\n\n"
              . "When: " . date('l, F j, Y', strtotime($r['delivery_at'])) . ', ' . rr_pickup_window($r) . "\nWhere: " . $r['delivery_location']
              . "\n\nBring your staff ID (the one you registered with) and the printed request form: " . rr_form_link($r['id']) . "\n\n- DRRM Office"
            : "🔔 Delivery reminder: your resource request #" . $r['id'] . " (" . $r['request_type'] . ") will be delivered TOMORROW.\n\n"
              . "When: " . rr_delivery_when($r['delivery_at']) . "\nWhere: " . $r['delivery_location'] . "\n\nPlease make sure someone from the barangay is there to receive and count the items.\n\n- DRRM Office";
        if (rr_message($r['user_id'], $text)) { $conn->query('UPDATE resource_requests SET remind_day_before_at = NOW() WHERE id = ' . (int)$r['id']); $sent++; }
    }
    // Delivery day, from 6:00 AM (only when it was approved before today).
    $rows = $conn->query("SELECT * FROM resource_requests WHERE status = 'approved' AND delivery_status IN ('scheduled', 'out') AND remind_today_at IS NULL
        AND DATE(delivery_at) = CURDATE() AND HOUR(NOW()) >= 6 AND DATE(reviewed_at) < CURDATE()")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $text = rr_is_pickup($r)
            ? "📦 Pick-up today: the items for your resource request #" . $r['id'] . " (" . $r['request_type'] . ") are ready for pick-up TODAY, " . rr_pickup_window($r) . ".\n\n"
              . "Where: " . $r['delivery_location'] . "\n\nBring your staff ID and the printed request form: " . rr_form_link($r['id']) . "\n\n- DRRM Office"
            : "🚚 Delivery today: your resource request #" . $r['id'] . " (" . $r['request_type'] . ") arrives TODAY at " . date('g:i A', strtotime($r['delivery_at'])) . ".\n\n"
              . "Where: " . $r['delivery_location'] . "\n\nPlease have someone ready to receive the items.\n\n- DRRM Office";
        if (rr_message($r['user_id'], $text)) { $conn->query('UPDATE resource_requests SET remind_today_at = NOW() WHERE id = ' . (int)$r['id']); $sent++; }
    }
    // Missed pick-ups: the window ended and nobody claimed the items. The barangay has RR_GRACE_DAYS to ask for a new date (with a reason).
    $rows = $conn->query("SELECT * FROM resource_requests WHERE status = 'approved' AND fulfillment = 'pickup' AND delivery_status = 'scheduled'
        AND received_at IS NULL AND pickup_end IS NOT NULL AND pickup_end < NOW()")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $conn->query("UPDATE resource_requests SET delivery_status = 'missed', missed_at = NOW(), missed_count = missed_count + 1, missed_total = missed_total + 1,
            miss_excused = 0, resched_date = NULL, resched_reason = NULL, resched_note = NULL, resched_at = NULL WHERE id = $id AND delivery_status = 'scheduled'");
        if ($conn->affected_rows !== 1) continue;
        rr_history($id, 'Missed pick-up: ' . date('M j, Y', strtotime($r['delivery_at'])) . ', ' . rr_pickup_window($r));
        $text = '⚠️ Missed pick-up: the items for your resource request #' . $id . ' (' . $r['request_type'] . ') were not claimed on ' . date('l, F j, Y', strtotime($r['delivery_at'])) . ' (' . rr_pickup_window($r) . ").\n\n"
            . 'Please ask for a new pick-up date and tell us why the items were not picked up, by ' . date('F j, Y', strtotime('+' . RR_GRACE_DAYS . ' days')) . ":\n"
            . "Resource Requests > 🚚 Deliveries & Pick-ups > 📅 Request new pick-up date.\n\n"
            . 'After ' . RR_MAX_MISSES . " missed pick-ups (not excused), the request can be cancelled and the items returned to the city stock.\n\n- DRRM Office";
        if (rr_message($r['user_id'], $text)) $sent++;
        audit_log('RESOURCE_PICKUP_MISSED', 'request #' . $id, 'Not claimed ' . date('M j, Y', strtotime($r['delivery_at'])) . ' ' . rr_pickup_window($r) . ' at ' . $r['delivery_location'], 'Failed', 0, 'Delivery reminders', 'system');
    }
    // Overdue deliveries: the delivery day ended and the items were not delivered or received. Delivery is the DRRM Office's
    // job, so the barangay is not penalised: it is told, and the DRRMC Admin sets a new date with a reason.
    $rows = $conn->query("SELECT * FROM resource_requests WHERE status = 'approved' AND (fulfillment IS NULL OR fulfillment = 'delivery')
        AND delivery_status IN ('scheduled', 'out') AND received_at IS NULL AND overdue_at IS NULL AND delivery_at IS NOT NULL AND DATE(delivery_at) < CURDATE()")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $conn->query("UPDATE resource_requests SET overdue_at = NOW(), overdue_total = overdue_total + 1 WHERE id = $id AND overdue_at IS NULL");
        if ($conn->affected_rows !== 1) continue;
        $on = date('l, F j, Y', strtotime($r['delivery_at']));
        rr_history($id, 'Delivery did not arrive: ' . date('M j, Y g:i A', strtotime($r['delivery_at'])) . ' at ' . $r['delivery_location']);
        $text = '⚠️ Delivery delayed: the items for your resource request #' . $id . ' (' . $r['request_type'] . ') were scheduled for ' . $on . ' but were not marked delivered.' . "\n\n"
            . "We are sorry for the delay. The DRRM Office will set a new delivery date and message you.\n\n"
            . "If the items DID arrive, count them and tap ✅ Received in Resource Requests > 🚚 Deliveries & Pick-ups.\n\n- DRRM Office";
        if (rr_message($r['user_id'], $text)) $sent++;
        audit_log('RESOURCE_DELIVERY_OVERDUE', 'request #' . $id, 'Not delivered on ' . date('M j, Y g:i A', strtotime($r['delivery_at'])) . ' at ' . $r['delivery_location'] . '; waiting for a new date', 'Failed', 0, 'Delivery reminders', 'system');
    }
    return $sent;
}

const RR_GRACE_DAYS = 3;   // days after a missed pick-up to ask for a new date
const RR_MAX_MISSES = 2;   // unexcused missed pick-ups before the DRRMC Admin may cancel the request
// Reasons a barangay gives for a missed pick-up.
function rr_missed_reasons() {
    return ['Bad weather / typhoon / flooding', 'No available vehicle or transportation', 'No available staff (on duty or emergency response)',
        'Staff was sick or had an emergency', 'Schedule conflict (meeting or barangay activity)'];
}
// Adds one dated line to a request's pick-up history (shown on the admin card and the print form).
function rr_history($request_id, $line) {
    global $conn;
    $entry = date('M j, Y g:i A') . ' - ' . $line;
    $st = $conn->prepare("UPDATE resource_requests SET pickup_history = CONCAT_WS('\n', NULLIF(pickup_history, ''), ?) WHERE id = ?");
    $id = (int)$request_id;
    $st->bind_param('si', $entry, $id); $st->execute(); $st->close();
}
function rr_history_lines(array $r) { return array_values(array_filter(explode("\n", (string)($r['pickup_history'] ?? '')), 'strlen')); }
// Last day the barangay can ask for a new date after the current miss.
function rr_grace_until(array $r) { return !empty($r['missed_at']) ? strtotime(date('Y-m-d 23:59:59', strtotime($r['missed_at'] . ' +' . RR_GRACE_DAYS . ' days'))) : null; }
// The DRRMC Admin may cancel an unclaimed pick-up once the misses reach the limit or the grace period ended with no new date asked.
function rr_can_cancel(array $r) {
    if (($r['delivery_status'] ?? '') !== 'missed') return false;
    return (int)$r['missed_count'] >= RR_MAX_MISSES || (empty($r['resched_date']) && rr_grace_until($r) < time());
}

// Delivery badge for a request: [css class, label]. "Received" = the barangay confirmed it got the items.
function rr_delivery_badge(array $r) {
    if (!empty($r['received_at'])) return ['received', '✅ Received'];
    $s = (string)($r['delivery_status'] ?? '');
    if ($s === 'missed') return ['missed', '⚠️ Missed pick-up'];
    if ($s === 'cancelled') return ['cancelled', '❌ Unclaimed - cancelled'];
    if (rr_is_pickup($r)) {
        if ($s === 'delivered') return ['delivered', '🤝 Claimed'];
        if ($s === 'scheduled') return ['scheduled', '📦 Ready for pick-up'];
    }
    if (rr_is_overdue($r)) return ['missed', '⚠️ Delivery overdue'];
    if ($s === 'delivered') return ['delivered', '📦 Delivered'];
    if ($s === 'out') return ['out', '🚚 Out for delivery'];
    if ($s === 'scheduled') return ['scheduled', '📅 Delivery scheduled'];
    return ['none', 'No delivery yet'];
}

/**
 * 5. The barangay confirms it received the delivery. Every item is added to its Main Inventory as an
 * "Obtained" transaction (item_tx_record: stock + Release & Obtained log + audit trail + forecast refresh),
 * the request is marked delivered/received, and the DRRM Office gets a message in the barangay's thread.
 * "Other items" are not inventory columns, so they are listed for the staff to add in the next report.
 * Returns ['ok' => bool, 'error' => text, 'added' => [label => qty], 'manual' => [text], 'failed' => [label => reason], 'units' => n].
 */
function rr_receive($request_id, $user_id, $photo = null) {
    global $conn;
    require_once __DIR__ . '/item_tx_lib.php';
    $rid = (int)$request_id; $uid = (int)$user_id;
    $st = $conn->prepare("SELECT rr.*, u.fullname, u.barangay FROM resource_requests rr INNER JOIN users u ON u.id = rr.user_id
        WHERE rr.id = ? AND rr.user_id = ? AND rr.status = 'approved' AND rr.delivery_at IS NOT NULL LIMIT 1");
    $st->bind_param('ii', $rid, $uid); $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
    if (!$r) return ['ok' => false, 'error' => 'This delivery is not available.'];
    if (!empty($r['received_at'])) return ['ok' => false, 'error' => 'You already confirmed this delivery on ' . date('M j, Y g:i A', strtotime($r['received_at'])) . '.'];
    if (in_array($r['delivery_status'], ['missed', 'cancelled'], true)) return ['ok' => false, 'error' => 'These items were not picked up yet.'];
    if (rr_is_pickup($r) && $r['delivery_status'] !== 'delivered') return ['ok' => false, 'error' => 'Confirm Received after the items were claimed at the DRRM Office.'];
    // Proof of delivery: a photo of the received items is required (same checks and private folder as chat photos).
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$photo || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => 'Attach a photo of the received items as proof of delivery.'];
    if ($photo['error'] === UPLOAD_ERR_INI_SIZE || $photo['error'] === UPLOAD_ERR_FORM_SIZE || ($photo['size'] ?? 0) > 8 * 1024 * 1024) return ['ok' => false, 'error' => 'The photo is too large. Please use a photo under 8 MB.'];
    $info = ($photo['error'] === UPLOAD_ERR_OK && is_uploaded_file($photo['tmp_name'])) ? @getimagesize($photo['tmp_name']) : false;
    if (!$info || !isset($types[$info['mime']])) return ['ok' => false, 'error' => 'The proof must be a JPG, PNG or WEBP photo.'];
    // Claim it first, so a double click can never add the items twice.
    $claim = $conn->prepare("UPDATE resource_requests SET received_at = NOW(), delivery_status = 'delivered', delivered_at = COALESCE(delivered_at, NOW()) WHERE id = ? AND received_at IS NULL");
    $claim->bind_param('i', $rid); $claim->execute(); $claimed = $claim->affected_rows === 1; $claim->close();
    if (!$claimed) return ['ok' => false, 'error' => 'This delivery was already confirmed.'];
    $dir = __DIR__ . '/uploads/messages';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied
");
    $photo_name = 'msg_' . bin2hex(random_bytes(12)) . '.' . $types[$info['mime']];
    if (!move_uploaded_file($photo['tmp_name'], $dir . '/' . $photo_name)) {
        $conn->query("UPDATE resource_requests SET received_at = NULL WHERE id = $rid");
        return ['ok' => false, 'error' => 'Unable to save the photo. Please try again.'];
    }
    $photo_path = 'uploads/messages/' . $photo_name;

    $items = rr_parse_items($r['items_requested']);
    $catalog = item_tx_catalog();
    $when = date('F j, Y g:i A');
    $added = []; $failed = []; $manual = []; $units = 0;
    foreach ($items['sections'] as $title => $rows) foreach ($rows as [$key, $label, $qty]) {
        if (!isset($catalog[$key])) { $manual[] = $qty . ' ' . $label; continue; } // not an inventory item
        $res = item_tx_record($uid, (string)$r['fullname'], $r['barangay'], 'obtained', $key, $qty,
            'DRRM Office (request #' . $rid . ')', (rr_is_pickup($r) ? 'Picked up at ' . $r['delivery_location'] . ', resource request #' . $rid : 'Delivered resource request #' . $rid) . ', received ' . $when,
            ['source' => 'delivery']); // medical supplies become batches; the staff add the expiry dates in Expiry Tracking
        if (!empty($res['ok'])) { $added[$label] = $qty; $units += $qty; } else { $failed[$label] = $res['error'] ?? 'not saved'; }
    }
    foreach ($items['other'] as [$name, $qty]) $manual[] = ($qty === null ? '' : $qty . ' ') . $name;

    // Nothing could be added (e.g. no equipment report yet): undo the claim so the barangay can try again.
    if (!$added && $failed) {
        $conn->query("UPDATE resource_requests SET received_at = NULL WHERE id = $rid");
        @unlink($dir . '/' . $photo_name);
        return ['ok' => false, 'error' => 'The items could not be added to your inventory: ' . reset($failed)];
    }
    $conn->query("UPDATE resource_requests SET received_units = " . (int)$units . " WHERE id = $rid");
    if (rr_is_overdue($r)) rr_history($rid, 'Received late (scheduled ' . date('M j, Y', strtotime($r['delivery_at'])) . ')');
    audit_log('RESOURCE_REQUEST_RECEIVED', 'request #' . $rid, 'Received ' . $when . ' at ' . $r['delivery_location'] . '; ' . count($added) . ' item(s), ' . $units . ' unit(s) added to the Main Inventory; proof photo attached'
        . ($manual ? '; to add by hand: ' . implode(', ', $manual) : '') . ($failed ? '; not added: ' . implode(', ', array_keys($failed)) : ''));

    // Tell the DRRM Office in the barangay's message thread (shows as an unread barangay message).
    $text = (rr_is_pickup($r) ? '✅ Pick-up received: ' : '✅ Delivery received: ') . barangay_label($r['barangay']) . ' received the items for resource request #' . $rid . ' (' . $r['request_type'] . ') on ' . $when . ".\n\n"
        . 'Added to our Main Inventory: ' . ($added ? implode(', ', array_map(function ($l, $q) { return $q . ' ' . $l; }, array_keys($added), $added)) : 'none') . '.'
        . ($manual ? "\nTo add in our next equipment report: " . implode(', ', $manual) . '.' : '')
        . ($failed ? "\nCould not be added: " . implode(', ', array_keys($failed)) . '.' : '')
        . "\n\n📷 Proof of delivery photo attached."
        . "\n\n- " . $r['fullname'];
    $m = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message, image_path) VALUES (?, ?, 'user', ?, ?)");
    $m->bind_param('iiss', $uid, $uid, $text, $photo_path); $m->execute(); $proof_id = (int)$m->insert_id; $m->close();
    $conn->query('UPDATE resource_requests SET proof_message_id = ' . $proof_id . ' WHERE id = ' . $rid);
    return ['ok' => true, 'added' => $added, 'manual' => $manual, 'failed' => $failed, 'units' => $units];
}

// Link to the printable request form, written into messages; messages.php shows it as a "📄 Open request form" button.
function rr_form_link($request_id) { return 'request_print.php?id=' . (int)$request_id; }
