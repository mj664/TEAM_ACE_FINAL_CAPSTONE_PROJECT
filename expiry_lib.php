<?php
/**
 * Expiry tracking of medical supplies, by batch.
 *
 * Every lot of a medical supply that comes in (Item In, a received delivery, a submitted report) is a batch with its
 * own quantity and expiry date. The quantities of the active batches of an item always add up to the stock shown in
 * Main Inventory (exp_sync keeps them equal). Stock that goes out is taken from the batch that expires first
 * (FEFO - First Expired, First Out). Stock whose expiry was not recorded is kept as an "expiry not set" batch until the
 * staff enter the date.
 *
 * Alerts: a batch is "expiring soon" one quarter (EXP_SOON_DAYS) before its expiry date (reminder again EXP_LAST_DAYS before) and
 * "expired" from the expiry date. The barangay staff get one message per alert (exp_tick), the DRRMC sees every
 * barangay on City Monitoring, and on its expiry date a batch is removed from the stock automatically (Item Out).
 */
require_once __DIR__ . '/forecast_engine.php'; // forecast_items(): the medical supplies

const EXP_SOON_DAYS = 90; // near-expiry alert: one quarter (3 months) before the expiry date
const EXP_LAST_DAYS = 30; // second reminder, one month before

function exp_install() {
    global $conn;
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS supply_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barangay VARCHAR(10) NOT NULL,
        item_key VARCHAR(40) NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        qty_in INT NOT NULL,                    -- quantity when the batch came in
        qty_left INT NOT NULL,                  -- quantity still in stock
        expiry_date DATE NULL,                  -- NULL = expiry not set yet
        lot_no VARCHAR(60) NULL,
        source VARCHAR(20) NOT NULL,            -- item_in / delivery / report / opening
        source_ref VARCHAR(60) NULL,            -- e.g. TXN-20261014-00090, request #23
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        alert_soon_at DATETIME NULL, alert_last_at DATETIME NULL, alert_expired_at DATETIME NULL,
        disposed_qty INT NOT NULL DEFAULT 0, disposed_at DATETIME NULL, disposed_by INT NULL, disposed_ref VARCHAR(60) NULL,
        cleared_at DATETIME NULL,               -- an expired batch stays visible until the item is restocked
        INDEX idx_batch_item (barangay, item_key, qty_left),
        INDEX idx_batch_expiry (expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
exp_install();
$__c = $conn->query("SHOW COLUMNS FROM supply_batches LIKE 'cleared_at'");
if ($__c && $__c->num_rows === 0) $conn->query('ALTER TABLE supply_batches ADD cleared_at DATETIME NULL AFTER disposed_ref');

function exp_items() { return forecast_items(); }
// Earliest expiry date that can be entered: tomorrow (on its expiry date an item already counts as expired).
function exp_min_date() { return date('Y-m-d', strtotime('+1 day')); }
function exp_is_medical($key) { return isset(exp_items()[$key]); }
function exp_brgy($b) { return (string)(int)preg_replace('/\D+/', '', (string)$b); }

// Stock of each medical supply in the barangay's latest report (what Main Inventory shows).
function exp_stock($barangay) {
    global $conn;
    $b = exp_brgy($barangay);
    $st = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id
        WHERE u.role = 'user' AND u.status = 'approved' AND u.barangay = ? ORDER BY er.submitted_at DESC, er.id DESC LIMIT 1");
    $st->bind_param('s', $b); $st->execute(); $row = $st->get_result()->fetch_assoc(); $st->close();
    $out = [];
    foreach (exp_items() as $k => $l) { $v = trim((string)($row[$k] ?? '')); $out[$k] = ($v === '' || !is_numeric($v)) ? 0 : max(0, (int)$v); }
    return $row ? $out : null;
}

// Adds a batch (after the stock went up by the same quantity).
function exp_add_batch($barangay, $item_key, $qty, $expiry = null, $lot = null, $source = 'item_in', $ref = null, $uid = null) {
    global $conn;
    if (!exp_is_medical($item_key) || (int)$qty < 1) return 0;
    $b = exp_brgy($barangay); $name = exp_items()[$item_key]; $q = (int)$qty;
    $expiry = $expiry ?: null; $lot = $lot !== null && trim((string)$lot) !== '' ? mb_substr(trim((string)$lot), 0, 60) : null;
    $st = $conn->prepare('INSERT INTO supply_batches (barangay, item_key, item_name, qty_in, qty_left, expiry_date, lot_no, source, source_ref, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->bind_param('sssiissssi', $b, $item_key, $name, $q, $q, $expiry, $lot, $source, $ref, $uid);
    $st->execute(); $id = (int)$st->insert_id; $st->close();
    // New stock of the item: its expired (already removed) batches no longer need to be shown.
    $cl = $conn->prepare('UPDATE supply_batches SET cleared_at = NOW() WHERE barangay = ? AND item_key = ? AND disposed_at IS NOT NULL AND cleared_at IS NULL');
    $cl->bind_param('ss', $b, $item_key); $cl->execute(); $cl->close();
    return $id;
}

// Expired batches removed from stock that are still waiting for the item to be restocked (shown until then).
function exp_expired_open($barangay = null) {
    global $conn;
    $w = $barangay !== null ? " AND barangay = '" . $conn->real_escape_string(exp_brgy($barangay)) . "'" : '';
    return $conn->query("SELECT * FROM supply_batches WHERE disposed_at IS NOT NULL AND cleared_at IS NULL$w ORDER BY expiry_date, id")->fetch_all(MYSQLI_ASSOC);
}

// Takes $qty out of an item's batches, the earliest expiry first (FEFO); batches with no date go last.
function exp_consume($barangay, $item_key, $qty) {
    global $conn;
    $b = exp_brgy($barangay); $left = (int)$qty;
    $st = $conn->prepare('SELECT id, qty_left FROM supply_batches WHERE barangay = ? AND item_key = ? AND qty_left > 0 ORDER BY expiry_date IS NULL, expiry_date, id');
    $st->bind_param('ss', $b, $item_key); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    foreach ($rows as $r) {
        if ($left <= 0) break;
        $take = min($left, (int)$r['qty_left']);
        $conn->query('UPDATE supply_batches SET qty_left = qty_left - ' . $take . ' WHERE id = ' . (int)$r['id']);
        $left -= $take;
    }
    return (int)$qty - $left;
}

/**
 * Keeps the batches equal to the stock in Main Inventory. Stock that came in without a recorded batch (a report or an
 * older entry) becomes an "expiry not set" batch; stock that went out is taken FEFO. Safe to call any time.
 * $source names where unrecorded stock came from (report / delivery / opening).
 */
function exp_sync($barangay, $source = 'report', $ref = null) {
    global $conn;
    $b = exp_brgy($barangay);
    if ($b === '0') return;
    $stock = exp_stock($b);
    if ($stock === null) return;
    $sums = [];
    $st = $conn->prepare('SELECT item_key, SUM(qty_left) s, COUNT(*) n FROM supply_batches WHERE barangay = ? GROUP BY item_key');
    $st->bind_param('s', $b); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $sums[$r['item_key']] = [(int)$r['s'], (int)$r['n']];
    $st->close();
    foreach ($stock as $k => $qty) {
        [$have, $count] = $sums[$k] ?? [0, 0];
        if ($qty > $have) exp_add_batch($b, $k, $qty - $have, null, null, $count ? $source : 'opening', $ref);
        elseif ($qty < $have) exp_consume($b, $k, $have - $qty);
    }
}

// Active batches of a barangay (optionally one item), earliest expiry first.
function exp_batches($barangay, $item_key = null) {
    global $conn;
    $b = exp_brgy($barangay);
    $sql = 'SELECT * FROM supply_batches WHERE barangay = ? AND qty_left > 0' . ($item_key ? ' AND item_key = ?' : '') . ' ORDER BY expiry_date IS NULL, expiry_date, id';
    $st = $conn->prepare($sql);
    if ($item_key) $st->bind_param('ss', $b, $item_key); else $st->bind_param('s', $b);
    $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    return $rows;
}

// ['expired' | 'soon' | 'ok' | 'unset', days left (negative = days since it expired), label]
function exp_status($expiry) {
    if (!$expiry) return ['unset', null, 'Expiry not set'];
    $days = (int)round((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) return ['expired', $days, 'Expired ' . abs($days) . ' day' . ($days === -1 ? '' : 's') . ' ago'];
    if ($days === 0) return ['expired', 0, 'Expired today']; // the expiry date counts as expired: removed from stock the same day
    if ($days <= EXP_SOON_DAYS) return ['soon', $days, 'Expires in ' . $days . ' day' . ($days === 1 ? '' : 's')];
    return ['ok', $days, 'Good until ' . date('M j, Y', strtotime($expiry))];
}

// Per item: nearest expiry, quantities by status. Plus barangay totals.
function exp_summary($barangay) {
    $items = []; $tot = ['expired' => 0, 'soon' => 0, 'unset' => 0, 'ok' => 0, 'batches' => 0];
    foreach (exp_batches($barangay) as $r) {
        [$s] = exp_status($r['expiry_date']);
        $k = $r['item_key'];
        if (!isset($items[$k])) $items[$k] = ['name' => $r['item_name'], 'nearest' => null, 'expired' => 0, 'soon' => 0, 'unset' => 0, 'ok' => 0, 'batches' => 0];
        $items[$k][$s] += (int)$r['qty_left']; $items[$k]['batches']++;
        $tot[$s] += (int)$r['qty_left']; $tot['batches']++;
        if ($r['expiry_date'] && ($items[$k]['nearest'] === null || $r['expiry_date'] < $items[$k]['nearest'])) $items[$k]['nearest'] = $r['expiry_date'];
    }
    $tot['removed'] = 0;
    foreach (exp_expired_open($barangay) as $r) {
        $k = $r['item_key'];
        if (!isset($items[$k])) $items[$k] = ['name' => $r['item_name'], 'nearest' => null, 'expired' => 0, 'soon' => 0, 'unset' => 0, 'ok' => 0, 'batches' => 0];
        $items[$k]['removed'] = ($items[$k]['removed'] ?? 0) + (int)$r['disposed_qty'];
        $tot['removed'] += (int)$r['disposed_qty'];
    }
    return ['items' => $items, 'total' => $tot];
}

// The barangay staff record the expiry date (and lot number) of a batch.
function exp_set_expiry($batch_id, $barangay, $date, $lot = null) {
    global $conn;
    $d = DateTime::createFromFormat('Y-m-d', (string)$date);
    if (!$d || $d->format('Y-m-d') !== $date) return 'Choose a valid expiry date.';
    if ($date < exp_min_date()) return 'The expiry date must be after today. Supplies that are already expired should not be kept in stock: take them out with Item Out.';
    if ($date > date('Y-m-d', strtotime('+15 years'))) return 'That expiry date does not look right.';
    $b = exp_brgy($barangay); $id = (int)$batch_id; $lot = $lot !== null && trim((string)$lot) !== '' ? mb_substr(trim((string)$lot), 0, 60) : null;
    $st = $conn->prepare('UPDATE supply_batches SET expiry_date = ?, lot_no = COALESCE(?, lot_no), alert_soon_at = NULL, alert_last_at = NULL, alert_expired_at = NULL WHERE id = ? AND barangay = ? AND qty_left > 0');
    $st->bind_param('ssis', $date, $lot, $id, $b); $st->execute(); $ok = $st->affected_rows === 1; $st->close();
    return $ok ? null : 'This batch is no longer in stock.';
}

/**
 * Alerts from the page poll (at most every 10 minutes, for the date changing overnight). A date entered by the staff or a
 * new batch is announced right away instead (exp_alert_scan for that barangay).
 */
function exp_tick() {
    global $conn;
    $conn->query("INSERT IGNORE INTO app_state (k, updated_at) VALUES ('expiry_last_run', '2000-01-01')");
    $claim = $conn->query("UPDATE app_state SET updated_at = NOW() WHERE k = 'expiry_last_run' AND updated_at <= NOW() - INTERVAL 10 MINUTE");
    if (!$claim || $conn->affected_rows !== 1) return 0;
    return exp_alert_scan();
}

// "today", "tomorrow", "in 12 days", "3 days ago"
function exp_when_text($expiry) {
    $d = (int)round((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    if ($d === 0) return 'today'; if ($d === 1) return 'tomorrow'; if ($d > 1) return 'in ' . $d . ' days';
    return abs($d) . ' day' . ($d === -1 ? '' : 's') . ' ago';
}

/**
 * One message per barangay listing its batches that just became "expiring within 3 months", "expiring within 1 month"
 * or "expired". Each batch is announced once per stage. $only_barangay limits it to one barangay (used right after the
 * staff enter a date). Returns the number of messages sent.
 */
function exp_alert_scan($only_barangay = null) {
    global $conn;
    require_once __DIR__ . '/resource_request_lib.php'; // rr_message(): messages from the DRRM Office account
    $where_b = $only_barangay !== null ? " AND barangay = '" . $conn->real_escape_string(exp_brgy($only_barangay)) . "'" : '';
    $stages = [
        // On the expiry date the batch leaves the stock automatically (it no longer counts anywhere).
        'alert_expired_at' => ["expiry_date <= CURDATE()", '⛔ Expired medical supplies were removed from your stock', 'They no longer count in your inventory, reports or forecast. Please discard them properly and restock.'],
        'alert_last_at' => ["expiry_date > CURDATE() AND expiry_date <= CURDATE() + INTERVAL " . EXP_LAST_DAYS . " DAY", '⚠️ Medical supplies expiring within 1 month', 'Use these first (they are taken out first automatically) or plan to replace them. They are removed from your stock automatically on their expiry date.'],
        'alert_soon_at' => ["expiry_date > CURDATE() + INTERVAL " . EXP_LAST_DAYS . " DAY AND expiry_date <= CURDATE() + INTERVAL " . EXP_SOON_DAYS . " DAY", '🗓 Medical supplies expiring within 3 months', 'Use these first and plan the restock before they expire.'],
    ];
    $sent = 0;
    foreach ($stages as $col => [$cond, $title, $advice]) {
        // Expired batches still in stock are always removed, even if an earlier "expired" alert was already sent for them.
        $seen = $col === 'alert_expired_at' ? '' : " AND $col IS NULL";
        $rows = $conn->query("SELECT * FROM supply_batches WHERE qty_left > 0 AND expiry_date IS NOT NULL$seen AND $cond$where_b ORDER BY barangay, expiry_date")->fetch_all(MYSQLI_ASSOC);
        if ($col === 'alert_expired_at') $rows = array_values(array_filter($rows, 'exp_auto_remove')); // only batches actually removed are announced
        $by = [];
        foreach ($rows as $r) $by[$r['barangay']][] = $r;
        foreach ($by as $b => $list) {
            $lines = array_map(function ($r) { return '• ' . $r['item_name'] . ': ' . (int)$r['qty_left'] . ' unit(s), expiry ' . date('M j, Y', strtotime($r['expiry_date'])) . ' (' . ($r['expiry_date'] <= date('Y-m-d') ? 'expired ' : 'expires ') . exp_when_text($r['expiry_date']) . ')' . ($r['lot_no'] ? ' (lot ' . $r['lot_no'] . ')' : ''); }, $list);
            $text = $title . ' - ' . barangay_label((int)$b) . "\n\n" . implode("\n", $lines) . "\n\n" . $advice . "\n\n- DRRM Office (Expiry Tracking)";
            $users = $conn->query("SELECT id FROM users WHERE role = 'user' AND status = 'approved' AND barangay = '" . $conn->real_escape_string($b) . "'")->fetch_all(MYSQLI_ASSOC);
            foreach ($users as $u) if (rr_message((int)$u['id'], $text)) $sent++;
            $ids = implode(',', array_map(function ($r) { return (int)$r['id']; }, $list));
            // Announcing a later stage also covers the earlier ones (no "30 days" message after the "7 days" one).
            $also = $col === 'alert_expired_at' ? ', alert_last_at = COALESCE(alert_last_at, NOW()), alert_soon_at = COALESCE(alert_soon_at, NOW())' : ($col === 'alert_last_at' ? ', alert_soon_at = COALESCE(alert_soon_at, NOW())' : '');
            $conn->query("UPDATE supply_batches SET $col = NOW()$also WHERE id IN ($ids)");
            audit_log('SUPPLY_EXPIRY_ALERT', barangay_label((int)$b), $title . ': ' . count($list) . ' batch(es)', 'Success', 0, 'Expiry Tracking', 'system');
        }
    }
    return $sent;
}

/**
 * Takes an expired batch out of the stock automatically: an Item Out "Expired - removed from stock" (so Main Inventory,
 * reports, low-stock alerts and the forecast stop counting it) recorded as the system, with the batch kept as removed.
 * Returns true when the batch was removed.
 */
function exp_auto_remove(array $r) {
    global $conn;
    require_once __DIR__ . '/item_tx_lib.php';
    $id = (int)$r['id']; $q = (int)$r['qty_left'];
    if ($q < 1) return false;
    $staff = $conn->query("SELECT id FROM users WHERE role = 'user' AND status = 'approved' AND barangay = '" . $conn->real_escape_string($r['barangay']) . "' ORDER BY id LIMIT 1")->fetch_assoc();
    if (!$staff) return false;
    // Take the batch out first so the Item Out below does not touch the other batches.
    $conn->query("UPDATE supply_batches SET qty_left = 0, disposed_qty = $q, disposed_at = NOW(), disposed_by = NULL WHERE id = $id AND qty_left = $q");
    if ($conn->affected_rows !== 1) return false;
    $res = item_tx_record((int)$staff['id'], 'Expiry Tracking (automatic)', $r['barangay'], 'released', $r['item_key'], $q, 'Expired - removed from stock',
        'Expired batch #' . $id . ' (expiry ' . date('M j, Y', strtotime($r['expiry_date'])) . ($r['lot_no'] ? ', lot ' . $r['lot_no'] : '') . '): removed automatically; discard the items',
        ['actor' => ['Expiry Tracking (automatic)', 'system']]);
    if (empty($res['ok'])) {
        $conn->query("UPDATE supply_batches SET qty_left = $q, disposed_qty = 0, disposed_at = NULL WHERE id = $id");
        error_log('Expiry auto-removal failed for batch #' . $id . ': ' . ($res['error'] ?? ''));
        return false;
    }
    $conn->query("UPDATE supply_batches SET disposed_ref = '" . $conn->real_escape_string($res['code']) . "' WHERE id = $id");
    audit_log('SUPPLY_EXPIRED_REMOVED', 'batch #' . $id, barangay_label((int)$r['barangay']) . ' - ' . $r['item_name'] . ': ' . $q . ' unit(s), expiry ' . $r['expiry_date'] . '; ' . $res['code'], 'Success', 0, 'Expiry Tracking (automatic)', 'system');
    return true;
}

// Expired batches removed from stock in the last $days days (for the Expiry Tracking tab and the DRRMC panel).
function exp_removed($barangay = null, $days = 30) {
    global $conn;
    $w = $barangay !== null ? " AND barangay = '" . $conn->real_escape_string(exp_brgy($barangay)) . "'" : '';
    return $conn->query("SELECT * FROM supply_batches WHERE disposed_at IS NOT NULL AND disposed_at >= NOW() - INTERVAL " . (int)$days . " DAY$w ORDER BY disposed_at DESC")->fetch_all(MYSQLI_ASSOC);
}

// City-wide list for the DRRMC: one row per barangay + item + status (expired / expiring within 3 months), each with
// its own units and earliest expiry date, expired first, then the soonest to expire.
function exp_city_rows() {
    global $conn;
    $rows = $conn->query("SELECT barangay, item_name, IF(expiry_date <= CURDATE(), 'expired', 'soon') st, SUM(qty_left) units, MIN(expiry_date) first_expiry, COUNT(*) batches
        FROM supply_batches WHERE qty_left > 0 AND expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL " . EXP_SOON_DAYS . " DAY
        GROUP BY barangay, item_name, st ORDER BY st = 'soon', first_expiry, barangay + 0")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) $r['label'] = barangay_label((int)$r['barangay']);
    return $rows;
}

// City-wide view for the DRRMC: every reporting barangay with its expired / expiring / not-set quantities.
function exp_city_overview() {
    global $conn;
    $out = [];
    $brgys = $conn->query("SELECT DISTINCT u.barangay FROM users u INNER JOIN equipment_reports er ON er.user_id = u.id WHERE u.role = 'user' AND u.status = 'approved' AND u.barangay REGEXP '^[0-9]+$' ORDER BY u.barangay + 0")->fetch_all(MYSQLI_ASSOC);
    foreach ($brgys as $r) {
        exp_sync($r['barangay'], 'opening');
        exp_alert_scan($r['barangay']); // removes expired batches (and sends any due alert once)
        $s = exp_summary($r['barangay']);
        $nearest = null;
        foreach ($s['items'] as $it) if ($it['nearest'] && ($nearest === null || $it['nearest'] < $nearest)) $nearest = $it['nearest'];
        $out[] = ['barangay' => (int)$r['barangay'], 'label' => barangay_label((int)$r['barangay'])] + $s['total'] + ['nearest' => $nearest, 'items' => $s['items']];
    }
    usort($out, function ($a, $b) { return [$b['expired'] + $b['removed'], $b['soon'], $b['unset']] <=> [$a['expired'] + $a['removed'], $a['soon'], $a['unset']] ?: $a['barangay'] <=> $b['barangay']; });
    return $out;
}

/**
 * AI label scan (optional): reads the expiry date and lot number printed on a box or label photo.
 * Returns ['ok' => bool, 'expiry' => 'YYYY-MM-DD'|'', 'lot' => '', 'note' => '', 'message' => ''].
 */
function exp_scan_label($path, $mime) {
    require_once __DIR__ . '/ai_ocr.php';
    if (!ai_ocr_configured()) return ['ok' => false, 'message' => 'Label scanning is not available. Please type the expiry date.'];
    $bytes = @file_get_contents($path);
    if ($bytes === false) return ['ok' => false, 'message' => 'The photo could not be read.'];
    $schema = ['type' => 'object', 'properties' => [
        'expiry_date' => ['type' => 'string'], 'lot_number' => ['type' => 'string'], 'found' => ['type' => 'boolean'], 'note' => ['type' => 'string']],
        'required' => ['expiry_date', 'lot_number', 'found', 'note'], 'additionalProperties' => false];
    $system = 'You read the printed expiry date of a medical supply from a photo of its box or label (e.g. "EXP", "Expiry", "Use by", "Best before"). '
        . 'Return expiry_date as YYYY-MM-DD. If only a month and year are printed, use the LAST day of that month. Do not use the manufacturing date (MFG/MFD). '
        . 'lot_number: the lot or batch number if printed, else "". found=false and expiry_date="" when no expiry date is readable. note: one short sentence.';
    $r = ai_vision_json($system, 'Read the expiry date and lot number on this label.', $bytes, $mime, $schema, 400, 45);
    if (empty($r['ok'])) return ['ok' => false, 'message' => 'The label could not be read right now. Please type the expiry date.'];
    $j = json_decode((string)($r['text'] ?? ''), true);
    if (!is_array($j)) return ['ok' => false, 'message' => 'The label could not be read. Please type the expiry date.'];
    $date = (string)($j['expiry_date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (empty($j['found']) || !$d || $d->format('Y-m-d') !== $date) return ['ok' => false, 'message' => 'No expiry date was found on the label. Please type it.'];
    return ['ok' => true, 'expiry' => $date, 'lot' => mb_substr(trim((string)($j['lot_number'] ?? '')), 0, 60), 'note' => mb_substr(trim((string)($j['note'] ?? '')), 0, 200), 'message' => 'Expiry date read from the label. Please check it before saving.'];
}

// Same sync for the barangay of a user (e.g. right after that user submits a report).
function exp_sync_user($user_id, $source = 'report') {
    global $conn;
    $r = $conn->query('SELECT barangay FROM users WHERE id = ' . (int)$user_id)->fetch_assoc();
    if ($r) exp_sync($r['barangay'], $source);
}

// Expiry fields posted with an Item In of a medical supply. Returns [batch array for item_tx_record, error or null].
function exp_batch_from_post($type, $item_key) {
    if ($type !== 'obtained' || !exp_is_medical($item_key)) return [[], null];
    if (!empty($_POST['no_expiry'])) return [['expiry' => null, 'lot' => $_POST['lot_no'] ?? null], null];
    $date = (string)($_POST['expiry_date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) return [[], 'Enter the expiry date printed on the medical supply (or tick "No expiry date printed").'];
    if ($date < date('Y-m-d', strtotime('-5 years')) || $date > date('Y-m-d', strtotime('+15 years'))) return [[], 'That expiry date does not look right. Please check it.'];
    if ($date <= date('Y-m-d')) return [[], 'This item is already expired (' . date('M j, Y', strtotime($date)) . '). Expired supplies cannot be added to the stock; discard them instead.'];
    return [['expiry' => $date, 'lot' => $_POST['lot_no'] ?? null], null];
}

// The expiry fields of an Item In form; the script shows them only for a medical supply.
function exp_fields_html() {
    return '<div class="exp-fields" hidden>'
        . '<div class="exp-row"><label>Expiry date <b class="exp-req">(required for medical supplies)</b><input type="date" name="expiry_date" class="exp-date" min="' . exp_min_date() . '" max="' . date('Y-m-d', strtotime('+15 years')) . '"></label>'
        . '<label>Lot / batch no. <span class="exp-opt">(optional)</span><input type="text" name="lot_no" class="exp-lot" maxlength="60" placeholder="e.g. L2405A"></label></div>'
        . '<div class="exp-row exp-tools">' . exp_scan_control('📷 Scan expiry date')
        . '<label class="exp-none"><input type="checkbox" name="no_expiry" value="1" class="exp-noexp"> No expiry date printed</label></div>'
        . '<p class="exp-scan-msg" aria-live="polite"></p>'
        . '</div>';
}

// One "Scan" button with a small menu: use the camera, or upload a photo of the label.
function exp_scan_control($label = '📷 Scan') {
    return '<div class="exp-scanmenu"><button type="button" class="exp-scan-toggle" aria-haspopup="true" aria-expanded="false">' . e($label) . ' <span>▾</span></button>'
        . '<div class="exp-scan-opts" hidden><button type="button" class="exp-cam">📷 Use camera</button>'
        . '<label class="exp-upl">🖼 Upload photo<input type="file" accept="image/jpeg,image/png,image/webp" class="exp-scan-file"></label></div></div>';
}

// Styles + script for exp_fields_html(). window.expFields(form, type, itemKey) shows/hides the fields.
function exp_fields_assets() {
    $keys = json_encode(array_keys(exp_items()));
    ob_start(); ?>
<style>
  .exp-fields { margin:8px 0; padding:10px 12px; border:1px solid #f5c48a; border-radius:10px; background:#fffaf4; }
  .exp-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; } .exp-row + .exp-row { margin-top:8px; }
  .exp-fields label { display:grid; gap:4px; font-size:12px; font-weight:700; color:#9a4a00; text-transform:none; letter-spacing:0; }
  .exp-fields input[type=date], .exp-fields input[type=text] { width:100%; box-sizing:border-box; padding:8px 9px; border:1px solid #d0d5dd; border-radius:7px; font:inherit; font-weight:400; }
  .exp-req { color:#b42318; font-weight:600; } .exp-opt { color:#98a2b3; font-weight:400; }
  .exp-tools { align-items:center; }
  .exp-scan { position:relative; display:inline-flex !important; align-items:center; justify-content:center; padding:8px 10px; border:1px dashed #ea7a00; border-radius:8px; background:#fff; color:#c2410c !important; cursor:pointer; text-align:center; }
  .exp-scan input { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; }
  .exp-none { display:flex !important; align-items:center; gap:6px; color:#475467 !important; font-weight:600 !important; }
  .exp-none input { width:auto; margin:0; }
  .exp-inline { margin:4px 0 0; padding:0; border:0; background:none; }
  .exp-inline .exp-scan-msg { flex-basis:100%; margin:0; } .exp-inline .exp-scan-msg:empty { display:none; }
  .exp-scan-sm { padding:6px 10px !important; font-size:12.5px; font-weight:700; white-space:nowrap; }
  .exp-scan-msg { margin:6px 0 0; font-size:12px; color:#475467; } .exp-scan-msg.ok { color:#067647; } .exp-scan-msg.err { color:#b42318; }
  .exp-scan-btns { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
  .exp-cam { display:inline-flex; align-items:center; gap:5px; margin:0; padding:7px 12px; border:0; border-radius:8px; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font:inherit; font-size:12.5px; font-weight:800; cursor:pointer; width:auto; white-space:nowrap; text-transform:none; letter-spacing:0; }
  .exp-cam:hover { filter:brightness(1.06); }
  .exp-scanmenu { position:relative; display:inline-block; }
  .exp-scan-toggle { display:inline-flex; align-items:center; gap:6px; margin:0; padding:7px 12px; border:0; border-radius:8px; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font:inherit; font-size:12.5px; font-weight:800; cursor:pointer; width:auto; white-space:nowrap; text-transform:none; letter-spacing:0; }
  .exp-scan-toggle span { font-size:10px; }
  .exp-scan-opts { position:absolute; z-index:50; top:calc(100% + 4px); left:0; min-width:170px; padding:5px; background:#fff; border:1px solid #eaecf0; border-radius:10px; box-shadow:0 10px 24px rgba(16,24,40,.16); }
  .exp-scan-opts .exp-cam, .exp-scan-opts .exp-upl { display:flex !important; align-items:center; gap:8px; width:100%; margin:0; padding:8px 10px; border:0; border-radius:7px; background:transparent; color:#344054 !important; font:inherit; font-size:13px; font-weight:600 !important; cursor:pointer; text-align:left; white-space:nowrap; position:relative; }
  .exp-scan-opts .exp-cam:hover, .exp-scan-opts .exp-upl:hover { background:#fff7ed; filter:none; }
  .exp-upl input { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; }
  /* Live camera scanner */
  .exp-camm { position:fixed; inset:0; z-index:3000; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(16,24,40,.75); }
  .exp-camm[hidden] { display:none; }
  .exp-camm-box { width:min(560px,100%); max-height:94vh; display:flex; flex-direction:column; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.35); }
  .exp-camm-head { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font-weight:800; }
  .exp-camm-head button { border:0; background:transparent; color:#fff; font-size:24px; line-height:1; cursor:pointer; width:auto; margin:0; padding:0 4px; }
  .exp-camm-view { position:relative; flex:0 1 auto; height:min(420px, 60vh); background:#000; overflow:hidden; }
  .exp-camm-view video, .exp-camm-view img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
  .exp-camm-view [hidden] { display:none !important; }
  .exp-camm-frame { position:absolute; left:12%; right:12%; top:30%; bottom:30%; border:3px solid rgba(255,255,255,.9); border-radius:10px; box-shadow:0 0 0 999px rgba(0,0,0,.35); pointer-events:none; }
  .exp-camm-hint { position:absolute; left:0; right:0; bottom:8px; text-align:center; color:#fff; font-size:12.5px; font-weight:700; text-shadow:0 1px 3px #000; }
  .exp-camm-foot { display:flex; flex-wrap:wrap; gap:8px; justify-content:space-between; align-items:center; padding:12px 16px; }
  .exp-camm-foot p { margin:0; flex:1 1 200px; font-size:12.5px; color:#475467; } .exp-camm-foot p.err { color:#b42318; } .exp-camm-foot p.ok { color:#067647; }
  .exp-camm-foot button { margin:0; padding:9px 16px; border:0; border-radius:8px; font:inherit; font-weight:800; cursor:pointer; width:auto; text-transform:none; letter-spacing:0; }
  .exp-camm-snap { background:#12b76a; color:#fff; } .exp-camm-snap:disabled { opacity:.5; cursor:not-allowed; }
  .exp-camm-flip, .exp-camm-retry { background:#f2f4f7; color:#344054; }
  @media (max-width:560px) { .exp-row { grid-template-columns:1fr; } }
</style>
<script>
// Shows the expiry fields for an Item In of a medical supply; the date is required unless "No expiry date printed".
window.expFields = function (form, type, itemKey) {
  var box = form.querySelector('.exp-fields'); if (!box) return;
  var show = type === 'obtained' && <?= $keys ?>.indexOf(itemKey) !== -1;
  box.hidden = !show;
  var date = box.querySelector('.exp-date'), none = box.querySelector('.exp-noexp');
  date.required = show && !none.checked; date.disabled = none.checked;
  if (!show) { date.value = ''; box.querySelector('.exp-lot').value = ''; none.checked = false; box.querySelector('.exp-scan-msg').textContent = ''; }
};
document.addEventListener('change', function (e) {
  var box = e.target.closest('.exp-fields'); if (!box) return;
  var form = box.closest('form');
  if (e.target.classList.contains('exp-noexp')) { var d = box.querySelector('.exp-date'); d.required = !e.target.checked; d.disabled = e.target.checked; if (e.target.checked) d.value = ''; }
  if (e.target.classList.contains('exp-scan-file') && e.target.files[0]) {
    expSendLabel(box, e.target.files[0]);
    e.target.value = '';
  }
});

// AI label scan: sends a photo of the label; the printed expiry date (and lot no.) is filled in for the staff to check.
// Calls done(ok, message) when finished (used by the camera pop-up).
window.expSendLabel = function (box, blob, done) {
  var form = box.closest('form'), msg = box.querySelector('.exp-scan-msg');
  if (msg) { msg.className = 'exp-scan-msg'; msg.textContent = 'Reading the label…'; }
  var fd = new FormData(); fd.append('csrf', (form && form.querySelector('input[name=csrf]') || {}).value || ''); fd.append('label', blob, 'label.jpg');
  fetch('expiry_scan.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
    if (j.ok) {
      var d = box.querySelector('.exp-date'); d.value = j.expiry; d.disabled = false;
      if (j.lot) box.querySelector('.exp-lot').value = j.lot;
      var ne = box.querySelector('.exp-noexp'); if (ne) ne.checked = false;
      var text = '✓ Expiry ' + j.expiry + (j.lot ? ', lot ' + j.lot : '') + ' read from the label. Please check it before saving.';
      if (msg) { msg.className = 'exp-scan-msg ok'; msg.textContent = text; }
      if (done) done(true, text);
    } else {
      var t = j.message || 'The label could not be read. Please type the date.';
      if (msg) { msg.className = 'exp-scan-msg err'; msg.textContent = t; }
      if (done) done(false, t);
    }
  }).catch(function () {
    var t = 'The label could not be read. Please type the date.';
    if (msg) { msg.className = 'exp-scan-msg err'; msg.textContent = t; }
    if (done) done(false, t);
  });
};

// Live camera scanner: preview, hold the label in the frame, Capture. Falls back to "Upload photo" when no camera is allowed.
(function () {
  var modal = null, video, img, msgEl, snapBtn, retryBtn, flipBtn, stream = null, box = null, facing = 'environment';
  function build() {
    modal = document.createElement('div'); modal.className = 'exp-camm'; modal.hidden = true;
    modal.innerHTML = '<div class="exp-camm-box" role="dialog" aria-modal="true" aria-label="Scan expiry date">'
      + '<div class="exp-camm-head"><span>📷 Scan the expiry date</span><button type="button" class="exp-camm-x" aria-label="Close">&times;</button></div>'
      + '<div class="exp-camm-view"><video playsinline muted></video><img alt="Captured label" hidden><div class="exp-camm-frame"></div><div class="exp-camm-hint">Hold the label with the EXP date inside the frame</div></div>'
      + '<div class="exp-camm-foot"><p>Starting the camera…</p><button type="button" class="exp-camm-flip" title="Switch camera">🔄</button><button type="button" class="exp-camm-retry" hidden>Retake</button><button type="button" class="exp-camm-snap" disabled>📸 Capture</button></div></div>';
    document.body.appendChild(modal);
    video = modal.querySelector('video'); img = modal.querySelector('img'); msgEl = modal.querySelector('.exp-camm-foot p');
    snapBtn = modal.querySelector('.exp-camm-snap'); retryBtn = modal.querySelector('.exp-camm-retry'); flipBtn = modal.querySelector('.exp-camm-flip');
    modal.querySelector('.exp-camm-x').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    snapBtn.addEventListener('click', snap);
    retryBtn.addEventListener('click', start);
    flipBtn.addEventListener('click', function () { facing = facing === 'environment' ? 'user' : 'environment'; start(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
  }
  function say(t, cls) { msgEl.className = cls || ''; msgEl.textContent = t; }
  function stop() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } }
  function close() { stop(); modal.hidden = true; }
  function start() {
    stop(); img.hidden = true; video.hidden = false; retryBtn.hidden = true; snapBtn.hidden = false; snapBtn.disabled = true; flipBtn.hidden = false;
    say('Starting the camera…');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('This browser cannot open the camera here. Use "Upload photo" instead.', 'err'); return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false })
      .then(function (s) { stream = s; video.srcObject = s; return video.play(); })
      .then(function () { snapBtn.disabled = false; say('Hold the label steady, then press Capture.'); })
      .catch(function (err) { say((err && err.name === 'NotAllowedError') ? 'Camera permission was denied. Allow the camera in the browser, or use "Upload photo".' : 'No camera was found. Use "Upload photo" instead.', 'err'); });
  }
  function snap() {
    if (!video.videoWidth) return;
    var c = document.createElement('canvas'); c.width = video.videoWidth; c.height = video.videoHeight;
    c.getContext('2d').drawImage(video, 0, 0);
    img.src = c.toDataURL('image/jpeg', 0.9); img.hidden = false; video.hidden = true; stop();
    snapBtn.disabled = true; flipBtn.hidden = true; say('Reading the label…');
    c.toBlob(function (blob) {
      window.expSendLabel(box, blob, function (ok, text) {
        if (ok) { say(text, 'ok'); setTimeout(close, 1400); }
        else { say(text + ' Try again with the date closer to the camera.', 'err'); retryBtn.hidden = false; snapBtn.hidden = true; }
      });
    }, 'image/jpeg', 0.9);
  }
  // Scan menu: open/close; choosing an option closes it.
  document.addEventListener('click', function (e) {
    var t = e.target.closest('.exp-scan-toggle');
    document.querySelectorAll('.exp-scanmenu').forEach(function (m) {
      var tg = m.querySelector('.exp-scan-toggle'), opts = m.querySelector('.exp-scan-opts');
      var open = t === tg && opts.hidden;
      if (t === tg || !m.contains(e.target) || e.target.closest('.exp-cam')) { opts.hidden = !open; tg.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    });
  });
  document.addEventListener('change', function (e) { var m = e.target.closest('.exp-scanmenu'); if (m && e.target.classList.contains('exp-scan-file')) m.querySelector('.exp-scan-opts').hidden = true; });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.exp-cam'); if (!b) return;
    box = b.closest('.exp-fields'); if (!box) return;
    if (!modal) build();
    modal.hidden = false; facing = 'environment'; start();
  });
})();
</script>
<?php
    return ob_get_clean();
}
