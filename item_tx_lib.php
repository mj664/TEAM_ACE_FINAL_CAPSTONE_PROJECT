<?php
/**
 * Item Out / Item In (check-out / check-in) transactions. Stored as txn_type 'released' / 'obtained'.
 *
 * The barangay's current stock ("Main Inventory") is its latest equipment report. A transaction changes that
 * stock and writes one row to item_transactions in the SAME database transaction: if either step fails,
 * both are rolled back. item_transactions is append-only (database triggers block UPDATE and DELETE).
 * Nothing in the existing tables is altered; the table only points to them with foreign keys.
 */
require_once __DIR__ . '/forecast_engine.php';

function item_tx_install() {
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS item_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        txn_type ENUM('released','obtained') NOT NULL,
        report_id INT NOT NULL,
        barangay VARCHAR(10) NOT NULL,
        item_key VARCHAR(40) NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        category VARCHAR(40) NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        qty_before INT NOT NULL,
        qty_after INT NOT NULL,
        processed_by INT NOT NULL,
        processed_by_name VARCHAR(255) NOT NULL,
        counterpart VARCHAR(150) NULL,
        remarks VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tx_brgy_date (barangay, created_at),
        INDEX idx_tx_item (item_key),
        INDEX idx_tx_date (created_at),
        CONSTRAINT fk_tx_report FOREIGN KEY (report_id) REFERENCES equipment_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_tx_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT chk_tx_qty CHECK (quantity > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Append-only: the log can never be edited or deleted, even directly in the database.
    foreach (['UPDATE', 'DELETE'] as $ev) {
        $name = 'trg_item_tx_no_' . strtolower($ev);
        $r = $conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$name'");
        if ($r && $r->num_rows === 0) {
            $conn->query("CREATE TRIGGER $name BEFORE $ev ON item_transactions FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_transactions is an audit log: records cannot be changed or deleted'");
        }
    }
}
item_tx_install();

// Items that can be released / obtained: [key => [label, category]].
function item_tx_catalog() {
    $out = [];
    foreach (forecast_equipment_items() as $k => $l) $out[$k] = [$l, 'Tools & Equipment'];
    foreach (forecast_items() as $k => $l) $out[$k] = [$l, 'Medical Supplies'];
    return $out;
}
function item_tx_code($row) { return 'TXN-' . date('Ymd', strtotime($row['created_at'])) . '-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT); }
function item_tx_type_label($t) { return $t === 'released' ? 'Item Out' : 'Item In'; }

// Stock level wording used across the system (medical: 0 out, 1-15 low; tools: 0 out).
function item_tx_level($category, $qty) {
    if ($qty === null) return 'Not reported';
    if ($qty === 0) return 'Out of stock';
    if ($category === 'Medical Supplies' && $qty <= 15) return 'Low';
    return 'Sufficient';
}

// The barangay's latest report (its current stock). With $lock the row is locked until the transaction ends.
function item_tx_latest_report($barangay, $lock = false) {
    global $conn;
    $b = (string)(int)$barangay;
    $st = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id
        WHERE u.role = 'user' AND u.status = 'approved' AND u.barangay = ? ORDER BY er.submitted_at DESC, er.id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $st->bind_param('s', $b);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Records a release or an obtained item. Returns ['ok' => true, 'id', 'code', 'before', 'after'] or ['ok' => false, 'error'].
 * Stock change + log entry are one database transaction (both saved, or neither).
 */
// $batch (medical supplies coming in): ['expiry' => YYYY-MM-DD|null, 'lot' => ..., 'source' => item_in|delivery] for expiry tracking.
function item_tx_record($user_id, $user_name, $barangay, $type, $item_key, $qty, $counterpart, $remarks, array $batch = []) {
    global $conn;
    $catalog = item_tx_catalog();
    $qty = (int)$qty;
    $counterpart = mb_substr(trim((string)$counterpart), 0, 150);
    $remarks = mb_substr(trim((string)$remarks), 0, 500);
    if (!in_array($type, ['released', 'obtained'], true)) return ['ok' => false, 'error' => 'Choose whether the item is released or obtained.'];
    if (!isset($catalog[$item_key])) return ['ok' => false, 'error' => 'Choose an item from the list.'];
    if ($qty < 1 || $qty > 99999) return ['ok' => false, 'error' => 'Enter a quantity from 1 to 99,999.'];
    if ($counterpart === '') return ['ok' => false, 'error' => $type === 'released' ? 'Enter the name of the person who took out the item.' : 'Enter the name of the person who returned or brought in the item.'];
    if ($remarks === '') return ['ok' => false, 'error' => 'Enter the purpose or remarks.'];
    [$label, $category] = $catalog[$item_key];
    $conn->begin_transaction();
    try {
        $report = item_tx_latest_report($barangay, true);
        if (!$report) throw new RuntimeException('Your barangay has no equipment report yet. Submit one first so there is a stock to update.');
        $raw = trim((string)($report[$item_key] ?? ''));
        $before = is_numeric($raw) ? max(0, (int)$raw) : 0;
        if ($type === 'released' && $qty > $before) throw new RuntimeException('Not enough stock: only ' . $before . ' ' . $label . ' left.');
        $after = $type === 'released' ? $before - $qty : $before + $qty;
        if ($after > 9999999) throw new RuntimeException('The quantity is too large.');
        // $item_key is checked against the catalog above, so it is a known column name.
        $up = $conn->prepare("UPDATE equipment_reports SET `$item_key` = ? WHERE id = ?");
        $after_s = (string)$after; $rid = (int)$report['id'];
        $up->bind_param('si', $after_s, $rid);
        $up->execute();
        if ($up->affected_rows !== 1) throw new RuntimeException('The stock could not be updated. Please try again.');
        $up->close();
        $ins = $conn->prepare('INSERT INTO item_transactions (txn_type, report_id, barangay, item_key, item_name, category, quantity, qty_before, qty_after,
            processed_by, processed_by_name, counterpart, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $b = (string)(int)$barangay; $uid = (int)$user_id; $cp = $counterpart !== '' ? $counterpart : null;
        $ins->bind_param('sissssiiiisss', $type, $rid, $b, $item_key, $label, $category, $qty, $before, $after, $uid, $user_name, $cp, $remarks);
        $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        return ['ok' => false, 'error' => ($ex instanceof RuntimeException && !($ex instanceof mysqli_sql_exception)) ? $ex->getMessage() : 'The transaction failed and nothing was changed. Please try again.'];
    }
    // After the commit: audit trail, forecast and restock reminders follow the new stock.
    $code = 'TXN-' . date('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    // $batch['actor'] = [label, role] records an automatic action (e.g. expiry removal) as the system, not as whoever is signed in.
    $actor = $batch['actor'] ?? null;
    audit_log('ITEM_' . strtoupper($type), $code, $label . ': ' . $qty . ' (' . $before . ' -> ' . $after . ')' . ($counterpart !== '' ? '; ' . ($type === 'released' ? 'to ' : 'from ') . $counterpart : ''), 'Success', $actor ? 0 : null, $actor[0] ?? null, $actor[1] ?? null);
    try { forecast_queue((int)$barangay, 1); restock_engine_trigger(true); } catch (Throwable $e) {}
    // Expiry tracking: a medical supply coming in becomes a batch; going out is taken from the batch that expires first (FEFO).
    try {
        require_once __DIR__ . '/expiry_lib.php';
        if ($type === 'obtained' && exp_is_medical($item_key)) exp_add_batch($barangay, $item_key, $qty, $batch['expiry'] ?? null, $batch['lot'] ?? null, $batch['source'] ?? 'item_in', $code, (int)$user_id);
        if ($type === 'obtained' && exp_is_medical($item_key) && !empty($batch['expiry'])) exp_alert_scan($barangay); // a batch that already expires soon is announced right away
        exp_sync($barangay);
    } catch (Throwable $e) { error_log('Expiry tracking: ' . $e->getMessage()); }
    return ['ok' => true, 'id' => $id, 'code' => $code, 'before' => $before, 'after' => $after, 'label' => $label];
}

// Log search. $f: q, from, to (Y-m-d), type, barangay. Returns rows, newest first (max $limit).
function item_tx_search(array $f, $limit = 500) {
    global $conn;
    $where = []; $types = ''; $args = [];
    if (!empty($f['barangay'])) { $where[] = 't.barangay = ?'; $types .= 's'; $args[] = (string)(int)$f['barangay']; }
    if (in_array($f['type'] ?? '', ['released', 'obtained'], true)) { $where[] = 't.txn_type = ?'; $types .= 's'; $args[] = $f['type']; }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 't.created_at >= ?'; $types .= 's'; $args[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 't.created_at <= ?'; $types .= 's'; $args[] = $f['to'] . ' 23:59:59'; }
    if (trim((string)($f['q'] ?? '')) !== '') {
        $q = '%' . trim($f['q']) . '%';
        $where[] = '(t.item_name LIKE ? OR t.counterpart LIKE ? OR t.remarks LIKE ? OR t.processed_by_name LIKE ? OR t.category LIKE ? OR t.id = ?)';
        $types .= 'sssssi'; array_push($args, $q, $q, $q, $q, $q);
        $args[] = (int)preg_replace('/^TXN-\d{8}-/i', '', trim($f['q']));
    }
    $sql = 'SELECT t.* FROM item_transactions t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . (int)$limit;
    $st = $conn->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

// Live status of one item in one barangay (for the "click an item" popup).
function item_tx_live($barangay, $item_key) {
    $cat = item_tx_catalog();
    if (!isset($cat[$item_key])) return null;
    $r = item_tx_latest_report($barangay);
    $raw = $r ? trim((string)($r[$item_key] ?? '')) : '';
    $qty = ($r && is_numeric($raw)) ? max(0, (int)$raw) : null;
    return ['item' => $cat[$item_key][0], 'category' => $cat[$item_key][1], 'barangay' => barangay_label($barangay), 'qty' => $qty,
        'level' => item_tx_level($cat[$item_key][1], $qty), 'report_id' => $r ? (int)$r['id'] : null,
        'report_date' => $r ? date('M j, Y g:i A', strtotime($r['submitted_at'])) : null,
        'updated' => $r && !empty($r['updated_at']) ? date('M j, Y g:i A', strtotime($r['updated_at'])) : null];
}

// Shared log table + filter bar (used by the staff page and the admin page).
function item_tx_filters_html(array $f, $show_brgy) {
    $h = '<form method="get" class="tx-filters">';
    $h .= '<input type="search" name="q" value="' . e($f['q'] ?? '') . '" placeholder="Search item, person, purpose, or TXN no.">';
    $h .= '<label>From<input type="date" name="from" value="' . e($f['from'] ?? '') . '"></label>';
    $h .= '<label>To<input type="date" name="to" value="' . e($f['to'] ?? '') . '"></label>';
    $h .= '<label>Type<select name="type"><option value="">All</option><option value="released"' . (($f['type'] ?? '') === 'released' ? ' selected' : '') . '>Item Out</option><option value="obtained"' . (($f['type'] ?? '') === 'obtained' ? ' selected' : '') . '>Item In</option></select></label>';
    if ($show_brgy) {
        $h .= '<label>Barangay<select name="barangay"><option value="">All</option>';
        foreach (forecast_barangays() as $b => $n) $h .= '<option value="' . $b . '"' . ((string)($f['barangay'] ?? '') === (string)$b ? ' selected' : '') . '>' . e(barangay_label($b)) . '</option>';
        $h .= '</select></label>';
    }
    $h .= '<button type="submit" class="tx-btn"><i class="fa-solid fa-filter"></i> Filter</button><a class="tx-btn ghost" href="?">Reset</a></form>';
    return $h;
}
function item_tx_table_html(array $rows, $show_brgy) {
    if (!$rows) return '<p class="tx-empty">No transactions found.</p>';
    $h = '<div class="tx-table-wrap"><table class="tx-table" id="txTable"><thead><tr><th>Transaction ID</th><th>Date &amp; time</th>' . ($show_brgy ? '<th>Barangay</th>' : '')
       . '<th>Item</th><th>Category</th><th>Type</th><th class="num">Qty</th><th class="num">Stock</th><th>Taken out / Returned by</th><th>Processed by</th><th>Remarks / Purpose</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $rel = $r['txn_type'] === 'released';
        $h .= '<tr><td class="code">' . e(item_tx_code($r)) . '</td><td>' . e(date('M j, Y g:i A', strtotime($r['created_at']))) . '</td>'
            . ($show_brgy ? '<td>' . e(barangay_label($r['barangay'])) . '</td>' : '')
            . '<td><a href="#" class="tx-item" data-b="' . e($r['barangay']) . '" data-item="' . e($r['item_key']) . '" title="Show current stock">' . e($r['item_name']) . '</a></td>'
            . '<td>' . e($r['category']) . '</td>'
            . '<td><span class="tx-type ' . ($rel ? 'out' : 'in') . '">' . ($rel ? '&#8593; Item Out' : '&#8595; Item In') . '</span></td>'
            . '<td class="num"><b>' . ($rel ? '-' : '+') . (int)$r['quantity'] . '</b></td>'
            . '<td class="num">' . (int)$r['qty_before'] . ' &rarr; ' . (int)$r['qty_after'] . '</td>'
            . '<td>' . e($r['counterpart'] ?? '-') . '</td><td>' . e($r['processed_by_name']) . '</td><td>' . e($r['remarks']) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}
function item_tx_assets() {
    return <<<'H'
<style>
  .tx-page { max-width:1500px; margin:0 auto; display:grid; gap:18px; }
  .tx-card { background:#fff; border-radius:14px; padding:18px 20px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
  .tx-card h3, .layout .tx-card h3 { margin:0 0 4px; padding:0; background:none; color:#1f2937; font-size:18px; }
  .tx-card > p { margin:0 0 14px; color:#667085; font-size:13px; }
  .tx-filters { display:flex !important; flex-direction:row !important; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:0 0 12px; }
  .tx-filters input[type=search] { flex:1 1 260px; width:auto; margin:0; }
  .tx-filters label { display:grid; gap:4px; font-size:11px; font-weight:700; color:#475467; text-transform:uppercase; letter-spacing:.3px; }
  .tx-filters input, .tx-filters select { margin:0; width:auto; padding:9px 10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:14px; text-transform:none; }
  .tx-btn { display:inline-flex; align-items:center; gap:6px; width:auto !important; margin:0 !important; padding:10px 16px; border:0; border-radius:8px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff !important; font:inherit; font-size:13px; font-weight:800; cursor:pointer; text-decoration:none; }
  .tx-btn.ghost { background:#fff; color:#c56a00 !important; border:1px solid #f5c48a; }
  .tx-table-wrap { overflow-x:auto; }
  .tx-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1100px; }
  .tx-table th { text-align:left; padding:9px 10px; background:#fff7ed; color:#9a4a00; font-size:11px; text-transform:uppercase; letter-spacing:.3px; border-bottom:2px solid #f5c48a; white-space:nowrap; }
  .tx-table td { padding:9px 10px; border-bottom:1px solid #f0f0f0; vertical-align:top; color:#344054; }
  .tx-table .num { text-align:right; white-space:nowrap; } .tx-table .code { font-family:Consolas, monospace; font-size:12px; white-space:nowrap; }
  .tx-type { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:800; white-space:nowrap; }
  .tx-type.out { background:#fee4e2; color:#b42318; } .tx-type.in { background:#dcfae6; color:#067647; }
  .tx-item { color:#c56a00; font-weight:700; text-decoration:none; } .tx-item:hover { text-decoration:underline; }
  .tx-empty { color:#667085; padding:14px 0; }
  .tx-count { font-size:13px; color:#475467; margin:0 0 8px; }
  .tx-live { position:fixed; inset:0; z-index:2000; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.5); }
  .tx-live[hidden] { display:none; }
  .tx-live-box { width:min(420px,100%); background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.3); }
  .tx-live-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; font-weight:800; }
  .tx-live-head button { width:auto; margin:0; border:0; background:transparent; color:#fff; font-size:24px; cursor:pointer; }
  .tx-live-body { padding:16px 18px; font-size:14px; color:#344054; line-height:1.8; }
  .tx-live-body .big { font-size:40px; font-weight:800; color:#1f2937; line-height:1.1; }
  .lv-out { color:#b42318; font-weight:800; } .lv-low { color:#b54708; font-weight:800; } .lv-ok { color:#067647; font-weight:800; }
</style>
<div class="tx-live" id="txLive" hidden><div class="tx-live-box"><div class="tx-live-head"><span id="txLiveTitle">Current stock</span><button type="button" id="txLiveClose" aria-label="Close">&times;</button></div><div class="tx-live-body" id="txLiveBody"></div></div></div>
<script>
(function () {
  // Click an item in the log: show its live stock from the barangay's Main Inventory (latest equipment report).
  var box = document.getElementById('txLive'), body = document.getElementById('txLiveBody');
  function close() { box.hidden = true; }
  document.getElementById('txLiveClose').addEventListener('click', close);
  box.addEventListener('click', function (e) { if (e.target === box) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('.tx-item'); if (!a) return;
    e.preventDefault();
    body.textContent = 'Loading...'; box.hidden = false;
    fetch('item_tx_live.php?b=' + encodeURIComponent(a.dataset.b) + '&item=' + encodeURIComponent(a.dataset.item), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { body.textContent = d.error || 'Not available.'; return; }
        var s = d.status, cls = s.level === 'Out of stock' ? 'lv-out' : (s.level === 'Low' ? 'lv-low' : 'lv-ok');
        document.getElementById('txLiveTitle').textContent = s.item + ' · ' + s.barangay;
        body.innerHTML = '<div class="big">' + (s.qty === null ? '-' : s.qty) + '</div>on hand now · <span class="' + cls + '">' + s.level + '</span><br>'
          + 'Category: ' + s.category + '<br>Main Inventory: equipment report #' + (s.report_id || '-') + (s.report_date ? ' (submitted ' + s.report_date + ')' : '')
          + (s.updated ? '<br>Last updated: ' + s.updated : '');
      })
      .catch(function () { body.textContent = 'Could not load the current stock.'; });
  });
})();
</script>
H;
}
