<?php
require_once 'init.php';
require_login();
if (is_role_in(['head_admin','official','admin'])) { header('Location: admin_dashboard.php'); exit; }

$msg = '';
$error = '';
$inventory_categories = ['Tools & Equipment', 'Medical Supplies', 'Other Items', 'Expiry']; // vehicles are not part of the system; Expiry = expiry tracking tab
// Result of a Release / Obtain made from Main Inventory (item_transactions.php sends the user back here).
$tx_return = $_SESSION['tx_flash_dashboard'] ?? null; unset($_SESSION['tx_flash_dashboard']);
if ($tx_return) $_POST['inventory_category'] = $tx_return['cat'];
$selected_inventory_category = $_POST['inventory_category'] ?? 'Tools & Equipment';
if (!in_array($selected_inventory_category, $inventory_categories, true)) {
  $selected_inventory_category = 'Tools & Equipment';
}
$user_id = (int)$_SESSION['user_id'];
// Expiry Tracking: record a batch's expiry date, or dispose of an expired batch (Item Out). Post/redirect/get.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exp_set'])) {
  require_once __DIR__ . '/expiry_lib.php';
  $eb = $conn->query('SELECT barangay, fullname FROM users WHERE id = ' . $user_id)->fetch_assoc() ?: ['barangay' => '', 'fullname' => ''];
  if (!csrf_valid($_POST['csrf'] ?? '')) $eerr = 'Your session expired. Please try again.';
  elseif (isset($_POST['exp_set'])) {
    $eerr = exp_set_expiry((int)$_POST['exp_set'], $eb['barangay'], (string)($_POST['expiry_date'] ?? ''), $_POST['lot_no'] ?? null);
    if (!$eerr) {
      audit_log('SUPPLY_EXPIRY_SET', 'batch #' . (int)$_POST['exp_set'], 'Expiry ' . (string)$_POST['expiry_date'] . (!empty($_POST['lot_no']) ? ', lot ' . $_POST['lot_no'] : ''));
      // Announce right away when the date already falls inside the alert window.
      [$est, , $elabel] = exp_status((string)$_POST['expiry_date']);
      $esent = exp_alert_scan($eb['barangay']);
      $exp_saved_note = $est === 'expired' ? ' ⛔ This batch is already expired, so it was removed from your stock automatically (recorded in the Audit Logs). Please discard the items.'
        : ($est === 'soon' ? ' ⚠️ ' . $elabel . '.' . ($esent ? ' A reminder was sent to your Messages.' : '') : '');
    }
  }
  $_SESSION['tx_flash_dashboard'] = ['ok' => !$eerr, 'cat' => 'Expiry', 'text' => $eerr ?: 'Expiry date saved.' . ($exp_saved_note ?? '')];
  header('Location: user_dashboard.php'); exit;
}
$advertisements = [];
$advertisement_result = $conn->query("SELECT id, title, image_path FROM advertisements WHERE is_active=1 ORDER BY created_at DESC, id DESC");
if ($advertisement_result) while ($advertisement = $advertisement_result->fetch_assoc()) $advertisements[] = $advertisement;
$profile_logo = '';
$user_barangay = $_SESSION['user_barangay'] ?? '';
$profile_logo_stmt = $conn->prepare('SELECT profile_logo, barangay FROM users WHERE id = ? LIMIT 1');
if ($profile_logo_stmt) {
  $profile_logo_stmt->bind_param('i', $user_id);
  $profile_logo_stmt->execute();
  $profile_row = $profile_logo_stmt->get_result()->fetch_assoc() ?: [];
  $profile_logo = $profile_row['profile_logo'] ?? '';
  $user_barangay = $profile_row['barangay'] ?? $user_barangay;
  $_SESSION['user_barangay'] = $user_barangay;
  $profile_logo_stmt->close();
}
// Expiry tracking: expired batches leave the stock before anything below reads the quantities.
try { require_once __DIR__ . '/expiry_lib.php'; exp_sync($user_barangay, 'opening'); exp_alert_scan($user_barangay); } catch (Throwable $e) { error_log('Expiry: ' . $e->getMessage()); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact_message'])) {
  $contact_message = trim($_POST['message'] ?? '');
  if ($contact_message === '') {
    $error = 'Please enter a message before sending.';
  } else {
    $contact_stmt = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'user', ?)");
    if ($contact_stmt) {
      $contact_stmt->bind_param('iis', $user_id, $user_id, $contact_message);
      if ($contact_stmt->execute()) {
        $msg = 'Message sent to the admin.';
      } else {
        $error = 'Unable to send your message.';
      }
      $contact_stmt->close();
    }
  }
}
$existing_report_stmt = $conn->prepare('SELECT * FROM equipment_reports WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1');
$existing_report_stmt->bind_param('i', $user_id);
$existing_report_stmt->execute();
$equipment_report = $existing_report_stmt->get_result()->fetch_assoc() ?: [];
$existing_report_stmt->close();

$chat_stmt = $conn->prepare("SELECT sender_role, IF(deleted_at IS NULL, message, 'This message was removed') AS message, created_at FROM contact_messages WHERE user_id = ? ORDER BY created_at ASC, id ASC");
$chat_messages = [];
if ($chat_stmt) {
  $chat_stmt->bind_param('i', $user_id);
  $chat_stmt->execute();
  $chat_result = $chat_stmt->get_result();
  while ($chat_row = $chat_result->fetch_assoc()) $chat_messages[] = $chat_row;
  $chat_stmt->close();
}

// Helper: update inventory item current_stock and status using the shared threshold.
function update_inventory_item($conn, $pattern, $qty, $section) {
  $qty = (int)$qty;
  $like = "%" . $pattern . "%";
  $find = $conn->prepare('SELECT id FROM inventory WHERE item_description LIKE ? LIMIT 1');
  if (!$find) return;
  $find->bind_param('s', $like);
  $find->execute();
  $existing = $find->get_result()->fetch_assoc();
  $find->close();

  if ($existing) {
    $status_sql = $section === 'Medical Supplies'
      ? ", status = CASE WHEN ? <= 15 THEN 'Low Stock' ELSE 'Verified' END"
      : '';
    $stmtu = $conn->prepare("UPDATE inventory SET current_stock = ?, section = ?$status_sql WHERE id = ?");
    if ($stmtu) {
      $item_id = (int)$existing['id'];
      if ($section === 'Medical Supplies') {
        $stmtu->bind_param('isii', $qty, $section, $qty, $item_id);
      } else {
        $stmtu->bind_param('isi', $qty, $section, $item_id);
      }
      $stmtu->execute();
      $stmtu->close();
    }
    return;
  }

  $description = ucwords($pattern);
  $status = $section === 'Medical Supplies' && $qty <= 15 ? 'Low Stock' : 'Verified';
  $insert = $conn->prepare('INSERT INTO inventory (item_description, section, status, current_stock) VALUES (?, ?, ?, ?)');
  if ($insert) {
    $insert->bind_param('sssi', $description, $section, $status, $qty);
    $insert->execute();
    $insert->close();
  }
}

// Save a quantity directly from the sectioned inventory list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inventory_item'])) {
  $item_id = (int)($_POST['item_id'] ?? 0);
  $quantity = max(0, (int)($_POST['current_stock'] ?? 0));
  $section_stmt = $conn->prepare('SELECT section FROM inventory WHERE id = ? LIMIT 1');
  $item_section = '';
  if ($section_stmt) {
    $section_stmt->bind_param('i', $item_id);
    $section_stmt->execute();
    $item_section = $section_stmt->get_result()->fetch_assoc()['section'] ?? '';
    $section_stmt->close();
  }

  $status = '';
  $notes = null;
  if ($item_section === 'Vehicles') {
    $allowed_vehicle_statuses = ['Operational', 'Under Maintenance', 'Not Operational'];
    $status = $_POST['vehicle_status'] ?? 'Operational';
    if (!in_array($status, $allowed_vehicle_statuses, true)) $status = 'Operational';
  } elseif ($item_section === 'Tools & Equipment') {
    $notes = trim($_POST['inventory_notes'] ?? '');
    $status = 'Verified';
  } elseif ($item_section === 'Medical Supplies') {
    $status = $quantity <= 15 ? 'Low Stock' : 'Verified';
  } else {
    $status = 'Verified';
  }

  $stmt = $notes === null
    ? $conn->prepare('UPDATE inventory SET current_stock = ?, status = ? WHERE id = ?')
    : $conn->prepare('UPDATE inventory SET current_stock = ?, status = ?, notes = ? WHERE id = ?');
  if ($stmt) {
    if ($notes === null) {
      $stmt->bind_param('isi', $quantity, $status, $item_id);
    } else {
      $stmt->bind_param('issi', $quantity, $status, $notes, $item_id);
    }
    if ($stmt->execute() && $stmt->affected_rows >= 0) {
      $msg = 'Inventory quantity saved successfully.';
      audit_log('INVENTORY_ITEM_SAVED', 'item #' . $item_id, 'quantity ' . $quantity);
    } else {
      $error = 'Unable to save inventory quantity.';
    }
    $stmt->close();
  }
}

// Record one unit used from any inventory item without allowing negative stock.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['use_inventory_item'])) {
  $item_id = (int)($_POST['item_id'] ?? 0);
  $section_stmt = $conn->prepare('SELECT section FROM inventory WHERE id = ? LIMIT 1');
  $item_section = '';
  if ($section_stmt) {
    $section_stmt->bind_param('i', $item_id);
    $section_stmt->execute();
    $item_section = $section_stmt->get_result()->fetch_assoc()['section'] ?? '';
    $section_stmt->close();
  }
  $status_update = $item_section === 'Medical Supplies'
    ? ", status = CASE WHEN GREATEST(current_stock - 1, 0) <= 15 THEN 'Low Stock' ELSE 'Verified' END"
    : '';
  $stmt = $conn->prepare("UPDATE inventory SET current_stock = GREATEST(current_stock - 1, 0)$status_update WHERE id = ?");
  if ($stmt) {
    $stmt->bind_param('i', $item_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
      $msg = 'One unit was deducted from inventory.';
      audit_log('STOCK_OUT', 'item #' . $item_id, '-1 unit');
    } else {
      $error = 'Inventory is already at zero or the item could not be updated.';
    }
    $stmt->close();
  }
}

// Add one unit to a medical supply without affecting other inventory sections.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_inventory_item'])) {
  $item_id = (int)($_POST['item_id'] ?? 0);
  $stmt = $conn->prepare("UPDATE inventory SET current_stock = current_stock + 1, status = CASE WHEN current_stock + 1 <= 15 THEN 'Low Stock' ELSE 'Verified' END WHERE id = ? AND section = 'Medical Supplies'");
  if ($stmt) {
    $stmt->bind_param('i', $item_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) { $msg = 'One unit added to the medical supply.'; audit_log('STOCK_IN', 'item #' . $item_id, '+1 unit'); }
    else $error = 'Unable to add quantity to this medical supply.';
    $stmt->close();
  }
}

// Save all medical supply quantities in one submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all_medical_supplies'])) {
  $quantities = $_POST['medical_quantities'] ?? [];
  $stmt = $conn->prepare("UPDATE inventory SET current_stock = ?, status = CASE WHEN ? <= 15 THEN 'Low Stock' ELSE 'Verified' END WHERE id = ? AND section = 'Medical Supplies'");
  if ($stmt) {
    foreach ($quantities as $item_id => $value) {
      $item_id = (int)$item_id;
      $quantity = max(0, (int)$value);
      $stmt->bind_param('iii', $quantity, $quantity, $item_id);
      $stmt->execute();
    }
    $stmt->close();
    $msg = 'All medical supply quantities were saved successfully.';
    audit_log('MEDICAL_SUPPLIES_SAVED', count($quantities) . ' items');
  } else {
    $error = 'Unable to save medical supply quantities.';
  }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inventory'])) {
  $ambulance_count = (int)($_POST['ambulance_count'] ?? 0);
  $fire_truck_count = (int)($_POST['fire_truck_count'] ?? 0);
  
  // Check if any equipment report exists for this user
  $check = $conn->query("SELECT id FROM equipment_reports WHERE user_id = $user_id");
  
  if ($check->num_rows > 0) {
    // Update existing report
    $stmt = $conn->prepare('UPDATE equipment_reports SET ambulance_count=?, fire_truck_count=?, 
      portable_oxygen=?, portable_oxygen_remarks=?,
      stretcher_scoop=?, stretcher_scoop_remarks=?,
      spider_strap=?, spider_strap_remarks=?,
      bp_automatic=?, bp_automatic_remarks=?,
      pulse_oximeter=?, pulse_oximeter_remarks=?,
      facemask=?, facemask_remarks=?,
      elastic_bandage=?, elastic_bandage_remarks=?,
      gloves=?, gloves_remarks=?,
      betadine_dropper=?, betadine_dropper_remarks=?,
      surgical_tape=?, surgical_tape_remarks=?,
      gauze_pad=?, gauze_pad_remarks=?,
      nasal_cannula=?, nasal_cannula_remarks=?,
      oxygen_mask=?, oxygen_mask_remarks=?,
      submitted_at=NOW()
      WHERE user_id=?');
      
    $portable_oxygen = $_POST['portable_oxygen'] ?? '';
    $portable_oxygen_remarks = $_POST['portable_oxygen_remarks'] ?? '';
    $stretcher_scoop = $_POST['stretcher_scoop'] ?? '';
    $stretcher_scoop_remarks = $_POST['stretcher_scoop_remarks'] ?? '';
    $spider_strap = $_POST['spider_strap'] ?? '';
    $spider_strap_remarks = $_POST['spider_strap_remarks'] ?? '';
    $bp_automatic = $_POST['bp_automatic'] ?? '';
    $bp_automatic_remarks = $_POST['bp_automatic_remarks'] ?? '';
    $pulse_oximeter = $_POST['pulse_oximeter'] ?? '';
    $pulse_oximeter_remarks = $_POST['pulse_oximeter_remarks'] ?? '';
    $facemask = $_POST['facemask'] ?? '';
    $facemask_remarks = $_POST['facemask_remarks'] ?? '';
    $elastic_bandage = $_POST['elastic_bandage'] ?? '';
    $elastic_bandage_remarks = $_POST['elastic_bandage_remarks'] ?? '';
    $gloves = $_POST['gloves'] ?? '';
    $gloves_remarks = $_POST['gloves_remarks'] ?? '';
    $betadine_dropper = $_POST['betadine_dropper'] ?? '';
    $betadine_dropper_remarks = $_POST['betadine_dropper_remarks'] ?? '';
    $surgical_tape = $_POST['surgical_tape'] ?? '';
    $surgical_tape_remarks = $_POST['surgical_tape_remarks'] ?? '';
    $gauze_pad = $_POST['gauze_pad'] ?? '';
    $gauze_pad_remarks = $_POST['gauze_pad_remarks'] ?? '';
    $nasal_cannula = $_POST['nasal_cannula'] ?? '';
    $nasal_cannula_remarks = $_POST['nasal_cannula_remarks'] ?? '';
    $oxygen_mask = $_POST['oxygen_mask'] ?? '';
    $oxygen_mask_remarks = $_POST['oxygen_mask_remarks'] ?? '';
    $update_types = 'ii' . str_repeat('s', 26) . 'i';
    $stmt->bind_param($update_types,
      $ambulance_count, $fire_truck_count,
      $portable_oxygen, $portable_oxygen_remarks,
      $stretcher_scoop, $stretcher_scoop_remarks,
      $spider_strap, $spider_strap_remarks,
      $bp_automatic, $bp_automatic_remarks,
      $pulse_oximeter, $pulse_oximeter_remarks,
      $facemask, $facemask_remarks,
      $elastic_bandage, $elastic_bandage_remarks,
      $gloves, $gloves_remarks,
      $betadine_dropper, $betadine_dropper_remarks,
      $surgical_tape, $surgical_tape_remarks,
      $gauze_pad, $gauze_pad_remarks,
      $nasal_cannula, $nasal_cannula_remarks,
      $oxygen_mask, $oxygen_mask_remarks,
      $user_id);
    
    if ($stmt->execute()) {
      $msg = '✓ Updated inventory saved successfully.';
      audit_log('EQUIPMENT_REPORT_UPDATED', 'user #' . $user_id);
      restock_engine_trigger(true); // a changed report may make this barangay critical (or optimal again)
    } else {
      $error = 'Error updating report: ' . $conn->error;
    }
    
    // After successful update, sync reported quantities into inventory
    if ($msg) {
      $map = [
        'portable_oxygen' => ['portable oxygen', 'Tools & Equipment'],
        'stretcher_scoop' => ['stretcher', 'Tools & Equipment'],
        'spider_strap' => ['spider strap', 'Tools & Equipment'],
        'bp_automatic' => ['bp', 'Tools & Equipment'],
        'pulse_oximeter' => ['pulse oximeter', 'Tools & Equipment'],
        'facemask' => ['facemask', 'Medical Supplies'],
        'elastic_bandage' => ['elastic bandage', 'Medical Supplies'],
        'gloves' => ['gloves', 'Medical Supplies'],
        'betadine_dropper' => ['betadine', 'Medical Supplies'],
        'surgical_tape' => ['surgical tape', 'Medical Supplies'],
        'gauze_pad' => ['gauze pad', 'Medical Supplies'],
        'nasal_cannula' => ['nasal cannula', 'Medical Supplies'],
        'oxygen_mask' => ['oxygen mask', 'Medical Supplies']
      ];

      foreach ($map as $field => [$pattern, $section]) {
        $val = isset($_POST[$field]) ? (int)$_POST[$field] : 0;
        update_inventory_item($conn, $pattern, $val, $section);
      }
    }
  } else {
    // Insert new report
    $stmt = $conn->prepare('INSERT INTO equipment_reports (user_id, ambulance_count, fire_truck_count,
      portable_oxygen, portable_oxygen_remarks,
      stretcher_scoop, stretcher_scoop_remarks,
      spider_strap, spider_strap_remarks,
      bp_automatic, bp_automatic_remarks,
      pulse_oximeter, pulse_oximeter_remarks,
      facemask, facemask_remarks,
      elastic_bandage, elastic_bandage_remarks,
      gloves, gloves_remarks,
      betadine_dropper, betadine_dropper_remarks,
      surgical_tape, surgical_tape_remarks,
      gauze_pad, gauze_pad_remarks,
      nasal_cannula, nasal_cannula_remarks,
      oxygen_mask, oxygen_mask_remarks)
          VALUES (' . implode(', ', array_fill(0, 29, '?')) . ')');
      
    $insert_types = 'iii' . str_repeat('s', 26);
        $portable_oxygen = $_POST['portable_oxygen'] ?? '';
        $portable_oxygen_remarks = $_POST['portable_oxygen_remarks'] ?? '';
        $stretcher_scoop = $_POST['stretcher_scoop'] ?? '';
        $stretcher_scoop_remarks = $_POST['stretcher_scoop_remarks'] ?? '';
        $spider_strap = $_POST['spider_strap'] ?? '';
        $spider_strap_remarks = $_POST['spider_strap_remarks'] ?? '';
        $bp_automatic = $_POST['bp_automatic'] ?? '';
        $bp_automatic_remarks = $_POST['bp_automatic_remarks'] ?? '';
        $pulse_oximeter = $_POST['pulse_oximeter'] ?? '';
        $pulse_oximeter_remarks = $_POST['pulse_oximeter_remarks'] ?? '';
        $facemask = $_POST['facemask'] ?? '';
        $facemask_remarks = $_POST['facemask_remarks'] ?? '';
        $elastic_bandage = $_POST['elastic_bandage'] ?? '';
        $elastic_bandage_remarks = $_POST['elastic_bandage_remarks'] ?? '';
        $gloves = $_POST['gloves'] ?? '';
        $gloves_remarks = $_POST['gloves_remarks'] ?? '';
        $betadine_dropper = $_POST['betadine_dropper'] ?? '';
        $betadine_dropper_remarks = $_POST['betadine_dropper_remarks'] ?? '';
        $surgical_tape = $_POST['surgical_tape'] ?? '';
        $surgical_tape_remarks = $_POST['surgical_tape_remarks'] ?? '';
        $gauze_pad = $_POST['gauze_pad'] ?? '';
        $gauze_pad_remarks = $_POST['gauze_pad_remarks'] ?? '';
        $nasal_cannula = $_POST['nasal_cannula'] ?? '';
        $nasal_cannula_remarks = $_POST['nasal_cannula_remarks'] ?? '';
        $oxygen_mask = $_POST['oxygen_mask'] ?? '';
        $oxygen_mask_remarks = $_POST['oxygen_mask_remarks'] ?? '';
    $stmt->bind_param($insert_types,
      $user_id, $ambulance_count, $fire_truck_count,
          $portable_oxygen, $portable_oxygen_remarks,
          $stretcher_scoop, $stretcher_scoop_remarks,
          $spider_strap, $spider_strap_remarks,
          $bp_automatic, $bp_automatic_remarks,
          $pulse_oximeter, $pulse_oximeter_remarks,
          $facemask, $facemask_remarks,
          $elastic_bandage, $elastic_bandage_remarks,
          $gloves, $gloves_remarks,
          $betadine_dropper, $betadine_dropper_remarks,
          $surgical_tape, $surgical_tape_remarks,
          $gauze_pad, $gauze_pad_remarks,
          $nasal_cannula, $nasal_cannula_remarks,
          $oxygen_mask, $oxygen_mask_remarks);
    
    if ($stmt->execute()) {
      $msg = '✓ Updated inventory saved successfully.';
      audit_log('EQUIPMENT_REPORT_UPDATED', 'user #' . $user_id);
      restock_engine_trigger(true); // a changed report may make this barangay critical (or optimal again)
    } else {
      $error = 'Error submitting report: ' . $conn->error;
    }

    // After successful insert, sync reported quantities into inventory
    if ($msg) {
      $map = [
        'portable_oxygen' => ['portable oxygen', 'Tools & Equipment'],
        'stretcher_scoop' => ['stretcher', 'Tools & Equipment'],
        'spider_strap' => ['spider strap', 'Tools & Equipment'],
        'bp_automatic' => ['bp', 'Tools & Equipment'],
        'pulse_oximeter' => ['pulse oximeter', 'Tools & Equipment'],
        'facemask' => ['facemask', 'Medical Supplies'],
        'elastic_bandage' => ['elastic bandage', 'Medical Supplies'],
        'gloves' => ['gloves', 'Medical Supplies'],
        'betadine_dropper' => ['betadine', 'Medical Supplies'],
        'surgical_tape' => ['surgical tape', 'Medical Supplies'],
        'gauze_pad' => ['gauze pad', 'Medical Supplies'],
        'nasal_cannula' => ['nasal cannula', 'Medical Supplies'],
        'oxygen_mask' => ['oxygen mask', 'Medical Supplies']
      ];

      foreach ($map as $field => [$pattern, $section]) {
        $val = isset($_POST[$field]) ? (int)$_POST[$field] : 0;
        update_inventory_item($conn, $pattern, $val, $section);
      }
    }
  }
}

$existing_report_stmt = $conn->prepare('SELECT * FROM equipment_reports WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1');
$existing_report_stmt->bind_param('i', $user_id);
$existing_report_stmt->execute();
$equipment_report = $existing_report_stmt->get_result()->fetch_assoc() ?: [];
$existing_report_stmt->close();

$res = $conn->query('SELECT * FROM inventory ORDER BY id DESC');
$inventory = [];
while($row = $res->fetch_assoc()) $inventory[] = $row;
$inventory_by_section = [];
foreach ($inventory as $item) {
  $section = trim($item['section'] ?? '') ?: 'Other';
  $inventory_by_section[$section][] = $item;
}
require_once __DIR__ . '/forecast_engine.php';
// Main Inventory shows the barangay's current stock: its latest equipment report (from any of its staff accounts,
// including changes made in Release & Obtain).
$inv_brgy = (string)(int)preg_replace('/\D+/', '', (string)$user_barangay);
$inv_stmt = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id WHERE u.role = 'user' AND u.status = 'approved' AND u.barangay = ? ORDER BY er.submitted_at DESC, er.id DESC LIMIT 1");
$inv_stmt->bind_param('s', $inv_brgy);
$inv_stmt->execute();
$inv_report = $inv_stmt->get_result()->fetch_assoc() ?: $equipment_report;
$inv_stmt->close();
// Expiry tracking (by batch): the batches always match the stock above.
require_once __DIR__ . '/expiry_lib.php';
exp_sync($inv_brgy, 'opening');
$exp = exp_summary($inv_brgy);
$exp_list = exp_batches($inv_brgy);
$exp_open = exp_expired_open($inv_brgy); // expired + removed, shown until the item is restocked
$exp_alerts = $exp['total']['expired'] + $exp['total']['soon'] + $exp['total']['removed'];
$report_catalog = [
  // Every tool and equipment item on the checklist (25 items).
  'Tools & Equipment' => array_map(function ($k, $l) { return ['name' => $l, 'field' => $k]; }, array_keys(forecast_equipment_items()), forecast_equipment_items()),
  'Medical Supplies' => [
    ['name' => 'Facemask', 'field' => 'facemask'],
    ['name' => 'Elastic Bandage', 'field' => 'elastic_bandage'],
    ['name' => 'Gloves', 'field' => 'gloves'],
    ['name' => 'Betadine Dropper', 'field' => 'betadine_dropper'],
    ['name' => 'Surgical Tape', 'field' => 'surgical_tape'],
    ['name' => 'Gauze Pad', 'field' => 'gauze_pad'],
    ['name' => 'Nasal Cannula', 'field' => 'nasal_cannula'],
    ['name' => 'Oxygen Mask', 'field' => 'oxygen_mask'],
  ],
];
$virtual_inventory = [];
foreach ($report_catalog as $section => $catalog) {
  if (!empty($inventory_by_section[$section])) continue;
  $virtual_inventory[$section] = [];
  foreach ($catalog as $reported_item) {
    $virtual_inventory[$section][] = [
      'id' => 0,
      'item_description' => $reported_item['name'],
      'section' => $section,
      'status' => $section === 'Vehicles' ? 'Operational' : ($section === 'Medical Supplies' ? 'Verified' : 'Available'),
      'required_quantity' => 0,
      'field' => $reported_item['field'],
      'current_stock' => (int)($inv_report[$reported_item['field']] ?? 0),
      'notes' => (string)($inv_report[$reported_item['field'] . '_remarks'] ?? ''),
      'virtual' => true,
    ];
  }
  $inventory_by_section[$section] = $virtual_inventory[$section];
}
// Items the barangay added under "4. Others" in its report.
$inv_others = report_other_items($inv_report ?: []);
if ($inv_others && empty($inventory_by_section['Other Items'])) {
  foreach ($inv_others as $o) $inventory_by_section['Other Items'][] = ['id' => 0, 'item_description' => $o['name'], 'section' => 'Other Items', 'status' => 'Available', 'required_quantity' => 0, 'current_stock' => (int)$o['qty'], 'notes' => $o['remarks'], 'virtual' => true];
}
$display_inventory = [];
foreach ($inventory_by_section as $section_items) {
  foreach ($section_items as $display_item) $display_inventory[] = $display_item;
}
$low_stock_count = 0;
$medical_low_items = [];
foreach ($display_inventory as $item) {
  if (($item['section'] ?? '') === 'Medical Supplies' && (int)$item['current_stock'] <= 15) {
    $low_stock_count++;
    $medical_low_items[] = $item;
  }
}
// Open on the restock list when something is low (unless the user was just working in another category).
if (!isset($_POST['inventory_category']) && $medical_low_items) $selected_inventory_category = 'Restock';
require_once __DIR__ . '/training_time.php';
$approved_training_schedules = [];
$training_schedule_stmt = $conn->prepare("SELECT td.* FROM training_drills td INNER JOIN training_requests tr ON tr.id = td.request_id WHERE tr.user_id = ? AND tr.status = 'approved' AND td.status = 'scheduled' ORDER BY td.scheduled_at ASC");
if ($training_schedule_stmt) {
  $training_schedule_stmt->bind_param('i', $user_id);
  $training_schedule_stmt->execute();
  $training_schedule_result = $training_schedule_stmt->get_result();
  while ($training_schedule = $training_schedule_result->fetch_assoc()) $approved_training_schedules[] = $training_schedule;
  $training_schedule_stmt->close();
}

// ---------------- Dashboard Overview (data straight from this barangay's equipment reports) ----------------
require_once __DIR__ . '/forecast_engine.php';
$ov_brgy = (string)(int)preg_replace('/\D+/', '', (string)$user_barangay);
$ov_reports = [];
if ($ov_brgy !== '0') {
  $ov_stmt = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id
    WHERE u.role = 'user' AND u.status = 'approved' AND u.barangay = ? ORDER BY er.submitted_at ASC, er.id ASC");
  $ov_stmt->bind_param('s', $ov_brgy);
  $ov_stmt->execute();
  $ov_reports = $ov_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $ov_stmt->close();
}
$ov_latest = $ov_reports ? end($ov_reports) : null;
$ov_catalog = ['Tools & Equipment' => forecast_equipment_items(), 'Medical Supplies' => forecast_items()];
$ov_items = [];
foreach ($ov_catalog as $ov_cat => $ov_list) {
  foreach ($ov_list as $ov_key => $ov_label) {
    $raw = $ov_latest ? trim((string)($ov_latest[$ov_key] ?? '')) : '';
    if ($raw === '' || !is_numeric($raw)) continue; // not reported
    $q = max(0, (int)$raw);
    // 0 = out of stock. Low stock (1-15) applies only to medical supplies, which are used up every day;
    // tools and equipment are reused, so any quantity above 0 is available.
    $lvl = $q === 0 ? 'Out of stock' : (($ov_cat === 'Medical Supplies' && $q <= 15) ? 'Low' : 'Sufficient');
    $ov_items[] = ['cat' => $ov_cat, 'key' => $ov_key, 'name' => $ov_label, 'qty' => $q, 'level' => $lvl];
  }
}
// Total stock of tools and medical supplies in each report day (for the trend line).
$ov_trend = [];
foreach ($ov_reports as $ov_r) {
  $sum = ['Tools & Equipment' => 0, 'Medical Supplies' => 0];
  foreach ($ov_catalog as $ov_cat => $ov_list) foreach ($ov_list as $ov_key => $l) { $v = trim((string)($ov_r[$ov_key] ?? '')); if (is_numeric($v)) $sum[$ov_cat] += max(0, (int)$v); }
  $ov_trend[date('Y-m-d', strtotime($ov_r['submitted_at']))] = $sum;
}
$ov_alerts = 0;
if ($ov_brgy !== '0') foreach (forecast_latest((int)$ov_brgy) as $ov_p) if ($ov_p['status'] === 'alert' && !$ov_p['is_resolved']) $ov_alerts++;
$ov_data = ['items' => $ov_items, 'trend' => $ov_trend, 'reports' => count($ov_reports), 'alerts' => $ov_alerts,
  'last' => $ov_latest ? date('M j, Y g:i A', strtotime($ov_latest['submitted_at'])) : ''];
// Open the overview first, unless the user was just working in the inventory.
$dash_default = ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_POST['inventory_category'])) ? 'inventory' : 'overview';
if ($tx_return) { if ($tx_return['ok']) $msg = $tx_return['text']; else $error = $tx_return['text']; }

$hazard_options = [
  'flood' => ['label' => 'Flood', 'sections' => ['Medical Supplies', 'Tools & Equipment'], 'keywords' => ['oxygen', 'stretcher', 'spider', 'gloves', 'facemask']],
  'earthquake' => ['label' => 'Earthquake', 'sections' => ['Tools & Equipment', 'Medical Supplies'], 'keywords' => ['stretcher', 'spider', 'bp', 'gloves']],
  'fire' => ['label' => 'Fire', 'sections' => ['Tools & Equipment', 'Medical Supplies'], 'keywords' => ['oxygen', 'facemask', 'gloves']],
  'typhoon' => ['label' => 'Typhoon', 'sections' => ['Medical Supplies', 'Tools & Equipment'], 'keywords' => ['facemask', 'gloves', 'oxygen']],
];
$selected_hazard = $_GET['hazard'] ?? 'flood';
if (!isset($hazard_options[$selected_hazard])) $selected_hazard = 'flood';
$show_preparedness_analysis = false; // moved to the Dashboard Overview tab
$hazard = $hazard_options[$selected_hazard];
$hazard_items = [];
$hazard_ready = 0;
$hazard_total = 0;
$analysis_inventory = $display_inventory;
foreach ($analysis_inventory as $item) {
  $item_name = strtolower((string)$item['item_description']);
  $is_relevant = in_array($item['section'] ?? 'Other', $hazard['sections'], true);
  foreach ($hazard['keywords'] as $keyword) {
    if (strpos($item_name, $keyword) !== false) $is_relevant = true;
  }
  if (!$is_relevant) continue;
  $required = (int)($item['required_quantity'] ?? 0);
  $current = (int)($item['current_stock'] ?? 0);
  $readiness = $required > 0 ? min(100, (int)round(($current / $required) * 100)) : ($current > 0 ? 100 : 0);
  $hazard_items[] = ['name' => $item['item_description'], 'readiness' => $readiness, 'current' => $current, 'required' => $required];
  $hazard_ready += $readiness;
  $hazard_total++;
}
$hazard_score = $hazard_total > 0 ? (int)round($hazard_ready / $hazard_total) : 0;

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>User Dashboard</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .form-section { display: none; }
    .form-section.active { display: block; animation: slideIn 0.3s ease; }
    @keyframes slideIn { from { opacity: 0; } to { opacity: 1; } }
    .section-header { background: var(--primary); color: white; padding: 16px; margin: -20px -20px 20px -20px; border-radius: 4px 4px 0 0; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(39, 174, 96, 0.1); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
    .equipment-item { background: #f9f9f9; padding: 15px; margin-bottom: 15px; border-radius: 4px; border-left: 3px solid var(--primary); }
    .equipment-item-label { font-weight: 600; color: #333; margin-bottom: 10px; }
    .form-actions { display: flex; gap: 10px; margin-top: 30px; justify-content: flex-end; }
    .form-actions button { padding: 12px 24px; font-size: 16px; border: none; border-radius: 4px; cursor: pointer; }
    .btn-next { background: var(--primary); color: white; }
    .btn-next:hover { background: #1e8449; }
    .btn-back { background: #95a5a6; color: white; }
    .btn-back:hover { background: #7f8c8d; }
    .btn-submit { background: #27ae60; color: white; }
    .btn-submit:hover { background: #1e8449; }
    .step-indicator { display: flex; gap: 20px; margin-bottom: 20px; justify-content: center; }
    .step { display: flex; align-items: center; gap: 8px; }
    .step-dot { width: 32px; height: 32px; border: 0; border-radius: 50%; background: #ecf0f1; color: #7f8c8d; display: flex; align-items: center; justify-content: center; font-weight: bold; cursor: pointer; }
    .step-dot:hover, .step-dot:focus-visible { background: #d5dbdb; outline: 2px solid var(--primary); outline-offset: 2px; }
    .step-dot.active { background: var(--primary); color: white; }
    .step-dot.completed { background: #27ae60; color: white; }
    .step-label { font-size: 14px; color: #666; }
    .step-label.active { color: var(--primary); font-weight: 600; }
    .required { color: #e74c3c; }
    .inventory-section { margin-bottom: 24px; }
    .inventory-category-nav { display:flex; gap:10px; flex-wrap:wrap; margin:16px 0 20px; }
    .inventory-category-btn { border:1px solid var(--primary); background:#fff; color:var(--primary); padding:10px 14px; border-radius:4px; font-weight:600; cursor:pointer; }
    .inventory-category-btn.active, .inventory-category-btn:hover { background:var(--primary); color:#fff; }
    .inventory-section.inventory-hidden { display:none; }
    .inventory-section h4 { background: #2d7a5c; color: #fff; padding: 12px 16px; margin: 0; }
    .inventory-alert-section { margin: 18px 0 24px; border: 1px solid #f2c6bd; border-radius: 5px; overflow: hidden; }
    .inventory-alert-section h4 { background: #fff1ed; color: #a33e28; padding: 13px 16px; margin: 0; border-left: 4px solid #e74c3c; }
    .inventory-alert-section table { margin: 0; }
    .inventory-alert-section .low-stock-status { color: #a33e28; font-weight: 700; white-space: nowrap; }
    .inventory-alert-empty { padding: 16px; color: #087443; background: #eef8f2; }
    .inventory-save { display:flex; flex-direction:row; gap:8px; align-items:center; flex-wrap:wrap; }
    .inventory-save input { width:80px; padding:7px; border:1px solid #ccc; border-radius:4px; }
    .inventory-save input[name="inventory_notes"] { width:150px; }
    .inventory-save select { width:auto; padding:7px; }
    .inventory-use { background:#e8753e; }
    .inventory-use:hover { background:#d6642e; }
    .inventory-add { background:#4b78b8; }
    .inventory-add:hover { background:#385f96; }
    .preparedness-panel { display:grid; grid-template-columns:minmax(220px,.7fr) minmax(300px,1.3fr); gap:20px; align-items:start; }
    .preparedness-score { padding:18px; border-radius:8px; background:linear-gradient(135deg,#eef8f2,#f7fbf8); border:1px solid #d5e9dc; }
    .preparedness-score strong { display:block; color:var(--primary); font-size:36px; line-height:1; margin:8px 0; }
    .preparedness-score select { width:100%; padding:9px; border:1px solid #cbded2; border-radius:4px; background:#fff; }
    .preparedness-chart { padding:4px 0; }
    .preparedness-row { margin-bottom:13px; }
    .preparedness-row-head { display:flex; justify-content:space-between; gap:12px; font-size:13px; margin-bottom:5px; }
    .preparedness-bar { height:12px; border-radius:8px; background:#e8eeea; overflow:hidden; }
    .preparedness-bar span { display:block; height:100%; border-radius:8px; background:linear-gradient(90deg,#e8753e,#f39c12,#2d7a5c); }
    .preparedness-note { margin:14px 0 0; padding:10px 12px; border-radius:5px; background:#fff7ed; color:#8a4b18; font-size:13px; }
    .preparedness-modal { display:none; position:fixed; inset:0; z-index:1100; align-items:center; justify-content:center; padding:24px; background:rgba(0,0,0,.45); }
    .preparedness-modal.active { display:flex; }
    .preparedness-modal-content { width:min(900px, 100%); max-height:90vh; overflow-y:auto; margin:0; background:#fff; box-shadow:0 8px 30px rgba(0,0,0,.25); }
    .preparedness-modal-header { display:flex; align-items:center; justify-content:space-between; gap:16px; }
    .preparedness-modal-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; padding:0 4px; }
    @media (max-width:760px) { .preparedness-panel { grid-template-columns:1fr; } }
    .medical-bulk-actions { display:flex; justify-content:flex-end; margin:12px 0; }
    .low-stock-more { margin-top:8px; cursor:pointer; }
    .contact-admin-float { position:fixed; left:24px; bottom:24px; width:48px; height:48px; display:flex; align-items:center; justify-content:center; background:var(--primary); color:#fff; border-radius:50%; font-size:22px; box-shadow:0 3px 10px rgba(0,0,0,.2); z-index:1001; }
    .contact-admin-float:hover { background:var(--primary-dark); color:#fff; text-decoration:none; transform:translateY(-2px); }
    .contact-chat-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; align-items:flex-end; justify-content:flex-start; padding:24px 24px 82px; }
    .contact-chat-modal.active { display:flex; }
    .contact-chat { width:min(380px, calc(100vw - 48px)); max-height:70vh; background:#fff; border-radius:8px; box-shadow:0 5px 20px rgba(0,0,0,.25); overflow:hidden; }
    .contact-chat-header { display:flex; justify-content:space-between; align-items:center; background:var(--primary); color:#fff; padding:14px 16px; }
    .contact-chat-close { border:0; background:transparent; color:#fff; font-size:24px; cursor:pointer; }
    .contact-chat-messages { max-height:280px; overflow-y:auto; padding:12px; background:#f7f7f7; }
    .contact-chat-message { padding:8px 10px; border-radius:6px; margin-bottom:8px; max-width:90%; font-size:13px; }
    .contact-chat-message.user { margin-left:auto; background:#d8eee3; }
    .contact-chat-message.admin { background:#fff; border:1px solid #ddd; }
    .contact-chat-message small { display:block; color:#777; margin-top:4px; }
    .contact-chat form { padding:12px; }
    .contact-chat textarea { min-height:70px; resize:vertical; }
    .equipment-save { grid-column: 1 / -1; justify-self: end; padding: 8px 14px; }
    #equipment-update-panel { display: none; }
    .user-dashboard-main { scrollbar-color: #9aa7b5 #eef1f4; scrollbar-width: thin; }
    .user-dashboard-main { display: block; height: 100%; overflow-y: scroll !important; overscroll-behavior: contain; }
    .user-dashboard-main { scrollbar-gutter: stable; }
    .user-dashboard-main::-webkit-scrollbar { width: 10px; }
    .user-dashboard-main::-webkit-scrollbar-track { background: #eef1f4; }
    .user-dashboard-main::-webkit-scrollbar-thumb { background: #9aa7b5; border: 2px solid #eef1f4; border-radius: 5px; }
    .user-dashboard-main::-webkit-scrollbar-thumb:hover { background: #687585; }
    .inventory-scroll-controls { position:sticky; top:12px; z-index:50; display:flex; flex-direction:column; gap:6px; width:34px; margin:0 0 -74px auto; }
    .inventory-scroll-controls button { width:34px; height:34px; border:1px solid #d0d5dd; border-radius:6px; background:#fff; color:#2d7a5c; font-size:20px; font-weight:700; line-height:1; cursor:pointer; box-shadow:0 2px 8px rgba(16,24,40,.14); }
    .inventory-scroll-controls button:hover { background:#2d7a5c; color:#fff; }
    @media (max-width:760px) { .inventory-scroll-controls { top:8px; } }
    .approved-training-card { border-left: 4px solid #2d7a5c; }
    .approved-training-card h4 { color: #1f2937; }
    .approved-training-item { padding: 10px 0; border-top: 1px solid #eaecf0; }
    .approved-training-item:first-of-type { border-top: 0; }
    .approved-training-item strong, .approved-training-item span, .approved-training-item small { display: block; margin-top: 3px; }
    .approved-training-item strong { color: #2d7a5c; font-size: 13px; }
    .approved-training-item span, .approved-training-item small { color: #667085; font-size: 12px; }
    .approved-training-read { margin-top: 8px; padding: 6px 10px; border: 1px solid #2d7a5c; border-radius: 4px; background: #fff; color: #2d7a5c; cursor: pointer; font-size: 12px; font-weight: 600; }
    .approved-training-read:hover { background: #2d7a5c; color: #fff; }
    .training-details-modal { display:none; position:fixed; inset:0; z-index:1200; align-items:center; justify-content:center; padding:24px; background:rgba(0,0,0,.45); }
    .training-details-modal.active { display:flex; }
    .training-details-content { width:min(460px, 100%); background:#fff; border-radius:8px; box-shadow:0 12px 40px rgba(0,0,0,.3); overflow:hidden; }
    .training-details-header { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 16px; background:var(--primary); color:#fff; }
    .training-details-header h3 { margin:0; font-size:17px; }
    .training-details-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; }
    .training-details-body { padding:16px; color:#475467; }
    .training-details-body p { margin:0 0 10px; }
    .training-details-body strong { color:#1f2937; }
    .inventory-map-card { padding:0; overflow:hidden; cursor:pointer; }
    .inventory-map-card h4 { margin:0; padding:13px 14px; color:#1f2937; font-size:15px; }
    .inventory-map-card p { margin:0; padding:0 14px 10px; color:#667085; font-size:12px; }
    .inventory-map-preview { height:180px; position:relative; overflow:hidden; border-top:1px solid #eaecf0; }
    .inventory-map-preview { cursor:pointer; }
    .inventory-map-preview iframe { display:block; width:100%; height:100%; border:0; pointer-events:none; }
    .inventory-map-trigger { position:absolute; inset:0; z-index:2; border:0; background:transparent; cursor:pointer; }
    .inventory-map-card.expanded { position:fixed; z-index:1100; inset:5vh 5vw; cursor:default; padding:0; border-radius:10px; box-shadow:0 12px 40px rgba(0,0,0,.3); }
    .inventory-map-card.expanded .inventory-map-preview { height:calc(100% - 75px); }
    .inventory-map-card.expanded .inventory-map-preview iframe { pointer-events:auto; }
    .inventory-map-card.expanded .inventory-map-trigger { display:none; }
    .inventory-map-card.expanded h4 { padding-right:50px; }
    .inventory-map-close { display:none; position:absolute; z-index:5; top:8px; right:10px; border:0; background:#fff; color:#344054; border-radius:50%; width:30px; height:30px; font-size:20px; cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,.2); }
    .inventory-map-card.expanded .inventory-map-close { display:block; }
    .inventory-map-backdrop { display:none; position:fixed; z-index:1099; inset:0; background:rgba(16,24,40,.5); }
    .inventory-map-backdrop.active { display:block; }
    .advertisement-card { overflow:hidden; }
    .advertisement-card h4 { color:#1f2937; }
    .advertisement-card p { color:#667085; font-size:12px; margin:6px 0 0; }
    .advertisement-carousel { position:relative; margin-top:12px; }
    .advertisement-slide { display:none; }
    .advertisement-slide.active { display:block; }
    .advertisement-photo { display:block; width:100%; height:150px; object-fit:cover; background:#f2f4f7; cursor:zoom-in; }
    .advertisement-nav { display:flex; justify-content:space-between; position:absolute; inset:50% 8px auto; transform:translateY(-50%); pointer-events:none; }
    .advertisement-nav button { width:30px; height:34px; border:0; border-radius:4px; background:rgba(255,255,255,.9); color:#1f2937; font-size:22px; line-height:1; cursor:pointer; pointer-events:auto; box-shadow:0 2px 6px rgba(0,0,0,.2); }
    .advertisement-nav button:hover { background:#fff; color:var(--primary); }
    @media (max-width:760px) { .inventory-map-card.expanded { inset:3vh 3vw; } }

    /* Main Inventory / Barangay Maps: two buttons side by side, each opens its own view. */
    .dash-tabs { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); margin:0 44px 16px 0; padding:6px; background:rgba(255,255,255,.9); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
    .dash-tab { display:flex; align-items:center; justify-content:center; gap:8px; padding:14px 12px; border:0; border-radius:10px; background:transparent; color:#344054; font:inherit; font-size:15px; font-weight:700; cursor:pointer; transition:background .15s, color .15s; }
    .dash-tab:hover { background:#fff7ed; color:#9a4a00; }
    .dash-tab:focus-visible { outline:2px solid #ff8c00; outline-offset:2px; }
    .dash-tab.active { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; box-shadow:0 4px 12px rgba(234,122,0,.3); }
    .dash-tab-count { padding:1px 8px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:11px; }
    .dash-tab.active .dash-tab-count { background:rgba(255,255,255,.9); }
    .dash-panel[hidden] { display:none !important; }
    .dash-map-card { margin:0; }
    .dash-map-sub { margin:10px 0 12px; color:#667085; font-size:13px; }
    /* Keep the page exactly one screen tall: the sidebar and header stay put, only the dashboard content scrolls. */
    .layout:has(.user-dashboard-page) { height:100vh; min-height:0; overflow:hidden; }
    .user-dashboard-page { height:100vh; min-width:0; }
    /* Map sized to the visible area below the header, buttons and map title, so it fits without scrolling. */
    .dash-map-frame { display:block; width:100%; height:max(360px, calc(100vh - 400px)); border:0; border-radius:10px; background:#f5f5f5; }
    .restock-btn { border-color:#f2a8a0 !important; color:#b42318 !important; }
    .restock-btn.active, .restock-btn:hover { background:linear-gradient(135deg,#dc2626,#ea7a00) !important; color:#fff !important; border-color:transparent !important; }
    .restock-count { display:inline-block; min-width:20px; margin-left:4px; padding:0 6px; border-radius:999px; background:#dc2626; color:#fff; font-size:11px; line-height:18px; text-align:center; }
    .restock-btn.active .restock-count, .restock-btn:hover .restock-count { background:#fff; color:#b42318; }
    .inventory-alert-section.inventory-section { margin-top:0; }
    @media (max-width:1200px) {
      .layout:has(.user-dashboard-page) { height:auto; min-height:100vh; overflow:visible; }
      .user-dashboard-page { height:auto; }
    }
    .dash-map-card h3 { display:flex; justify-content:space-between; align-items:center; gap:10px; }
    .dash-map-full { border:1px solid rgba(255,255,255,.6); border-radius:8px; padding:6px 12px; background:rgba(255,255,255,.18); color:#fff; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .dash-map-full:hover, .dash-map-full:focus-visible { background:rgba(255,255,255,.32); outline:none; }

    /* Floating buttons (bottom-right) that open the alerts / training / advertisement panels. */
    .float-dock { position:fixed; right:24px; bottom:24px; z-index:1050; display:flex; flex-direction:column; align-items:flex-end; gap:12px; }
    .float-buttons { display:flex; flex-direction:column; gap:10px; align-items:flex-end; }
    .float-btn { position:relative; display:flex; align-items:center; justify-content:center; width:52px; height:52px; border:0; border-radius:50%; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; font-size:22px; line-height:1; cursor:pointer; box-shadow:0 8px 20px rgba(234,122,0,.35); transition:transform .15s, filter .15s; }
    .float-btn:hover, .float-btn:focus-visible { transform:translateY(-2px); filter:brightness(1.08); outline:none; }
    .float-btn[aria-expanded="true"] { box-shadow:0 0 0 3px #fff, 0 8px 20px rgba(234,122,0,.35); }
    .float-btn.is-alert { animation:floatPulse 2.2s ease-in-out infinite; }
    @keyframes floatPulse { 0%,100% { box-shadow:0 8px 20px rgba(234,122,0,.35); } 50% { box-shadow:0 0 0 7px rgba(220,38,38,.22), 0 8px 20px rgba(234,122,0,.35); } }
    .float-btn-label { position:absolute; right:62px; white-space:nowrap; padding:5px 10px; border-radius:6px; background:#1f2937; color:#fff; font-size:12px; font-weight:600; opacity:0; pointer-events:none; transition:opacity .15s; }
    .float-btn:hover .float-btn-label, .float-btn:focus-visible .float-btn-label { opacity:1; }
    .float-badge { position:absolute; top:-4px; right:-4px; min-width:20px; height:20px; padding:0 5px; border-radius:10px; background:#dc2626; color:#fff; font-size:11px; font-weight:700; line-height:20px; text-align:center; border:2px solid #fff; }
    .float-panel { position:relative; width:min(340px, calc(100vw - 48px)); max-height:min(70vh, 560px); overflow-y:auto; border-radius:14px; box-shadow:0 16px 40px rgba(120,60,0,.28); animation:floatIn .15s ease-out; }
    .float-panel[hidden] { display:none !important; }
    @keyframes floatIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
    .float-panel > .info-card, .float-panel > .contacts-card { margin:0 !important; border-radius:14px; background:#fff !important; }
    .float-panel-close { position:absolute; top:8px; right:10px; z-index:2; width:28px; height:28px; border:0; border-radius:50%; background:#fff7ed; color:#9a4a00; font-size:18px; line-height:1; cursor:pointer; }
    .float-panel-close:hover, .float-panel-close:focus-visible { background:#ffedd5; outline:none; }
    .float-panel h4 { padding-right:34px; }
    @media (prefers-reduced-motion: reduce) { .float-btn.is-alert, .float-panel { animation:none; } }
    @media (max-width:640px) { .float-dock { right:16px; bottom:16px; } .float-btn { width:46px; height:46px; font-size:19px; } }
    /* Theme colours for the rest of the staff dashboard */
    .inventory-scroll-controls button { color:#c56a00; border-color:#f5c48a; }
    /* Four scroll buttons live in a right-hand gutter so they never cover the tables or the map. */
    .inventory-scroll-controls { margin:0 0 -154px auto; }
    .inventory-scroll-controls button:hover:not(:disabled) { background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; border-color:transparent; }
    .inventory-scroll-controls button:disabled { opacity:.4; cursor:default; box-shadow:none; }
    .dash-panel { margin-right:44px; }
    @media (max-width:560px) { .dash-panel { margin-right:40px; } }
    .user-dashboard-main { scrollbar-color:#f0b060 transparent; }
    .user-dashboard-main::-webkit-scrollbar-track { background:transparent; }
    .user-dashboard-main::-webkit-scrollbar-thumb { background:#f0b060; border-color:transparent; }
    .approved-training-card { border-left-color:#ff8c00; }
    .approved-training-item strong { color:#c56a00; }
    .approved-training-read { border-color:#f5c48a; color:#c56a00; }
    .approved-training-read:hover { background:linear-gradient(135deg,#ea7a00,#3f9b46); border-color:transparent; color:#fff; }
    /* Dashboard Overview: report-style dashboard (navy / blue / teal) */
    .dash-tabs { grid-template-columns:repeat(3, minmax(0,1fr)); }
    .ov { --ov-navy:#1e5631; --ov-blue:#e08a00; --ov-teal:#3f9b46; --ov-red:#c0392b; display:grid; gap:16px; margin:0 44px 20px 0; font-family:'Segoe UI', Roboto, Arial, sans-serif; }
    .ov-head { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; padding:18px 22px; border-radius:14px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; box-shadow:0 8px 22px rgba(120,60,0,.25); }
    .ov-title, .layout .ov-title { margin:0; padding:0; background:none; color:#fff; font-size:24px; font-weight:800; letter-spacing:.2px; }
    .ov-sub { margin:4px 0 0; font-size:13px; color:#fff7e8; }
    .ov-filter { display:flex; flex-direction:column; gap:4px; color:#fff !important; font-size:13px; font-weight:600; text-transform:none !important; letter-spacing:0 !important; }
    .ov-filter select { width:200px; padding:8px 10px; border:0; border-radius:4px; font:inherit; font-size:14px; color:#1f2937; }
    .ov-card { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 6px 18px rgba(16,24,40,.10); min-width:0; }
    .ov-card h4 { margin:0 0 10px; font-size:17px; font-weight:600; color:#1f2937; }
    .ov-card h4 small { font-size:12px; font-weight:400; color:#667085; }
    .ov-kpis { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:8px; text-align:center; padding:18px 10px; }
    .ov-kpi span { display:block; font-size:14px; color:#344054; min-height:36px; }
    .ov-kpi b { display:block; margin-top:4px; font-size:34px; font-weight:600; color:#1f2937; letter-spacing:.5px; }
    .ov-kpi b.ov-red { color:var(--ov-red); } .ov-kpi b.ov-blue { color:var(--ov-blue); }
    .ov-row { display:grid; gap:16px; }
    .ov-row-3 { grid-template-columns:repeat(3, minmax(0,1fr)); }
    .ov-row-2 { grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); }
    .ov-chart { position:relative; height:260px; }
    .ov-chart-tall { height:300px; } .ov-chart-short { height:200px; }
    .ov-table-wrap { max-height:300px; overflow:auto; }
    .ov-table { width:100%; border-collapse:collapse; font-size:14px; }
    .ov-table th { position:sticky; top:0; background:#fff; text-align:left; padding:8px 10px; border-bottom:2px solid #f5c48a; color:#1f2937; font-weight:700; cursor:pointer; white-space:nowrap; }
    .ov-table th.sorted::after { content:' ▾'; color:#c56a00; } .ov-table th.sorted.asc::after { content:' ▴'; }
    .ov-table td { padding:7px 10px; border-bottom:1px solid #eef0f2; color:#344054; }
    .ov-table tbody tr:nth-child(even) td { background:#fffaf3; }
    .ov-table .num { text-align:right; }
    .ov-lvl { font-weight:700; } .ov-lvl.out { color:var(--ov-red); } .ov-lvl.low { color:var(--ov-blue); } .ov-lvl.ok { color:var(--ov-teal); }
    .ov-prep-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .ov-prep-head h4 { margin:0; }
    .ov-prep-pick { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#344054 !important; text-transform:none !important; letter-spacing:0 !important; }
    .ov-prep-pick select { width:190px; padding:7px 10px; border:1px solid #f5c48a; border-radius:6px; font:inherit; font-size:14px; }
    .ov-prep-grid { display:grid; grid-template-columns:minmax(200px,.7fr) minmax(0,1.4fr) minmax(0,1fr); gap:18px; margin-top:12px; align-items:start; }
    .ov-prep-grid h5 { margin:0 0 6px; font-size:14px; font-weight:600; color:#475467; }
    .ov-prep-score { padding:18px; border-radius:12px; background:linear-gradient(160deg,#1e5631 0%,#2f7d3a 100%); color:#fff; }
    .ov-prep-score span { font-size:14px; color:#e3f3df; }
    .ov-prep-score b { display:block; font-size:48px; font-weight:700; line-height:1.1; margin:6px 0 10px; }
    .ov-prep-meter { height:10px; border-radius:999px; background:rgba(255,255,255,.2); overflow:hidden; }
    .ov-prep-meter i { display:block; height:100%; width:0; border-radius:999px; background:#f2a93b; transition:width .4s; }
    .ov-prep-score p { margin:12px 0 0; font-size:13px; line-height:1.5; color:#fff; }
    .ov-prep-score p.ov-prep-rule { font-size:11.5px; color:#cfe6ca; }
    @media (max-width:1100px) { .ov-prep-grid { grid-template-columns:1fr; } }
    .ov-help { margin:-4px 0 10px; font-size:12.5px; line-height:1.5; color:#667085; }
    .ov-prep-head .ov-help { flex-basis:100%; margin:4px 0 0; }
    .ov-kpi small { display:block; margin-top:4px; font-size:11.5px; color:#98a2b3; line-height:1.35; }
    .ov-empty { text-align:center; padding:30px; color:#475467; }
    @media (max-width:1200px) { .ov-kpis { grid-template-columns:repeat(3, minmax(0,1fr)); } .ov-row-3 { grid-template-columns:1fr 1fr; } }
    @media (max-width:860px) { .ov { margin-right:0; } .ov-row-3, .ov-row-2 { grid-template-columns:1fr; } .ov-kpis { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    /* Scroll arrows removed from the dashboard (kept in the page, hidden, so nothing else breaks) */
    .inventory-scroll-controls { display:none !important; }
    .dash-tabs, .ov { margin-right:0 !important; }
    /* Floating buttons: solid navy with a white ring so they stand out on the orange/green background */
    .float-dock .float-btn { background:#1f3b64; border:3px solid #fff; box-shadow:0 8px 22px rgba(15,23,42,.45); }
    .float-dock .float-btn:hover, .float-dock .float-btn:focus-visible { background:#2b4f86; filter:none; }
    .float-dock .float-btn[aria-expanded="true"] { background:#2b8cff; box-shadow:0 0 0 3px #1f3b64, 0 8px 22px rgba(15,23,42,.45); }
    .float-dock .float-btn.is-alert { background:#c0392b; }
    .float-dock .float-btn.is-alert:hover { background:#d9483a; }
    @media (max-width:560px) { .dash-tabs { grid-template-columns:1fr; margin-right:0; } }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div class="user-dashboard-page" style="flex:1;display:flex;flex-direction:column;min-height:0;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h2 style="margin:0;font-size:24px;">Welcome, <span style="color:var(--primary);"><?php echo e($_SESSION['user_name']); ?></span></h2>
        <p style="margin:4px 0 0 0;color:#666;font-size:13px;"><?php echo e(barangay_label($user_barangay)); ?></p>
      </div>
    </header>

    <div class="user-dashboard-content-shell" style="flex:1;display:flex;min-height:0;overflow:hidden;">
      <main class="main-content user-dashboard-main" id="dashboard-main" style="flex:1;min-height:0;overflow-y:auto;">
        <div class="inventory-scroll-controls" aria-label="Scroll controls">
          <button type="button" id="inventoryScrollTop" aria-label="Scroll to the top" title="Top">&#10514;</button>
          <button type="button" id="inventoryScrollUp" aria-label="Scroll up one screen" title="Scroll up">&uarr;</button>
          <button type="button" id="inventoryScrollDown" aria-label="Scroll down one screen" title="Scroll down">&darr;</button>
          <button type="button" id="inventoryScrollBottom" aria-label="Scroll to the bottom" title="Bottom">&#10515;</button>
        </div>
        <?php if($msg): ?><div class="notice" style="margin:20px;"><?php echo e($msg); ?></div><?php endif; ?>
        <?php if($error): ?><div class="errors" style="margin:20px;"><?php echo e($error); ?></div><?php endif; ?>
        <?php if($msg && $low_stock_count > 0): ?>
          <div class="errors" style="margin:20px;">
            <strong>⚠️ Low-stock notification:</strong>
            <?php echo $low_stock_count; ?> item<?php echo $low_stock_count === 1 ? '' : 's'; ?> now have 15 or fewer remaining.
            Review the Main Inventory above.
          </div>
        <?php endif; ?>

        <?php if ($exp_alerts): $exp_x = (int)$exp['total']['expired'] + (int)$exp['total']['removed']; $exp_s = (int)$exp['total']['soon']; ?>
          <div class="exp-banner<?php echo $exp_x ? ' x' : ''; ?>" role="alert">
            <span class="exp-banner-ico"><?php echo $exp_x ? '⛔' : '🗓'; ?></span>
            <span><b><?php echo $exp_x ? $exp_x . ' unit' . ($exp_x === 1 ? '' : 's') . ' of medical supplies expired and were removed from stock (restock needed)' : 'Medical supplies expiring soon'; ?></b><?php
              $near = []; foreach ($exp['items'] as $it) if ($it['expired'] || $it['soon'] || !empty($it['removed'])) $near[] = e($it['name']) . ' (' . (!empty($it['removed']) ? $it['removed'] . ' expired' : ($it['expired'] ? $it['expired'] . ' expired' : $it['soon'] . ', ' . e(exp_status($it['nearest'])[2]))) . ')';
              echo ' &middot; ' . implode(', ', $near); ?><?php if ($exp_s && $exp_x): ?> &middot; <?php echo $exp_s; ?> more expiring within 3 months<?php endif; ?></span>
            <button type="button" class="exp-banner-btn" onclick="var t=document.querySelector('[data-dash-tab=inventory]'); if(t) t.click(); selectInventoryCategory('Expiry');">Open Expiry Tracking &rarr;</button>
          </div>
        <?php endif; ?>
        <div class="dash-tabs" role="tablist" aria-label="Dashboard sections">
          <button type="button" class="dash-tab<?php echo $dash_default === 'overview' ? ' active' : ''; ?>" role="tab" id="dashTabOverview" aria-selected="<?php echo $dash_default === 'overview' ? 'true' : 'false'; ?>" aria-controls="dashPanelOverview" data-dash-tab="overview">📊 Dashboard Overview</button>
          <button type="button" class="dash-tab<?php echo $dash_default === 'inventory' ? ' active' : ''; ?>" role="tab" id="dashTabInventory" aria-selected="<?php echo $dash_default === 'inventory' ? 'true' : 'false'; ?>" aria-controls="dashPanelInventory" data-dash-tab="inventory">📦 Main Inventory<?php if ($medical_low_items): ?> <span class="dash-tab-count"><?php echo count($medical_low_items); ?> low</span><?php endif; ?></button>
          <button type="button" class="dash-tab" role="tab" id="dashTabMaps" aria-selected="false" aria-controls="dashPanelMaps" data-dash-tab="maps">🗺️ Barangay Maps</button>
        </div>

        <div class="dash-panel" data-dash-panel="overview" id="dashPanelOverview" role="tabpanel" aria-labelledby="dashTabOverview"<?php echo $dash_default === 'overview' ? '' : ' hidden'; ?>>
          <div class="ov">
            <div class="ov-head">
              <div>
                <h3 class="ov-title"><?php echo e(barangay_label($user_barangay)); ?> Inventory Dashboard</h3>
                <p class="ov-sub"><?php echo $ov_latest ? 'From your latest equipment report · ' . e($ov_data['last']) . ' · ' . count($ov_reports) . ' report' . (count($ov_reports) === 1 ? '' : 's') . ' so far' : 'No equipment report submitted yet'; ?></p>
              </div>
              <label class="ov-filter">Category
                <select id="ovCat"><option value="">All</option><option>Tools &amp; Equipment</option><option>Medical Supplies</option></select>
              </label>
            </div>
            <?php if (!$ov_items): ?>
              <div class="ov-card ov-empty"><p>📋 There is no equipment report for your barangay yet, so there is nothing to chart.</p><a class="btn" href="user_report.php">Submit Report</a></div>
            <?php else: ?>
            <div class="ov-card ov-kpis">
              <div class="ov-kpi"><span>Items reported</span><b id="kItems">0</b><small>items with a quantity in your latest report</small></div>
              <div class="ov-kpi"><span>Total quantity</span><b id="kQty">0</b><small>all units added together</small></div>
              <div class="ov-kpi"><span>Out-of-stock items</span><b id="kOut" class="ov-red">0</b><small>items at 0: get these first</small></div>
              <div class="ov-kpi"><span>Low-stock items</span><b id="kLow" class="ov-blue">0</b><small>medical supplies with 1 to 15 left</small></div>
              <div class="ov-kpi"><span>Forecast restock alerts</span><b><?php echo (int)$ov_alerts; ?></b><small>medical supplies the forecast says will run short in 30 days</small></div>
            </div>
            <div class="ov-card ov-prep">
              <div class="ov-prep-head">
                <h4>Disaster Preparedness Analysis <small>(from your latest equipment report)</small></h4><p class="ov-help">How ready your barangay is for the chosen disaster. Each disaster needs a set of items; an item is <b>100% ready</b> with at least 16. The score is the average of those items.</p>
                <label class="ov-prep-pick">Disaster type
                  <select id="ovHazard"><option value="flood">Flood</option><option value="typhoon">Typhoon</option><option value="earthquake">Earthquake</option><option value="fire">Fire</option><option value="medical">Medical emergency</option></select>
                </label>
              </div>
              <div class="ov-prep-grid">
                <div class="ov-prep-score">
                  <span id="ovPrepLabel">Flood preparedness</span>
                  <b id="ovPrepScore">0%</b>
                  <div class="ov-prep-meter"><i id="ovPrepMeter"></i></div>
                  <p id="ovPrepNote"></p>
                  <p class="ov-prep-rule">Each item is ready when it has at least <?php echo FORECAST_REORDER_LEVEL + 1; ?>. Out of stock counts as 0%.</p>
                </div>
                <div><h5>Items needed for this disaster</h5><p class="ov-help">Each bar is one item this disaster needs. <b style="color:#c0392b">Red</b> = below 50% or out of stock (restock first), <b style="color:#d98a00">orange</b> = partly ready, <b style="color:#3f9b46">green</b> = fully ready. Hover a bar to see how many you have.</p><div class="ov-chart ov-chart-tall"><canvas id="ovPrepChart"></canvas></div></div>
                <div><h5>Readiness by disaster type</h5><p class="ov-help">Your score for every disaster side by side, so you can see which one you are least ready for. Click a bar to analyse that disaster.</p><div class="ov-chart ov-chart-tall"><canvas id="ovHazardChart"></canvas></div></div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="dash-panel" data-dash-panel="inventory" id="dashPanelInventory" role="tabpanel" aria-labelledby="dashTabInventory"<?php echo $dash_default === 'inventory' ? '' : ' hidden'; ?>>
        <section class="card" style="margin:0 0 20px 0;">
          <h3 style="background:var(--primary);">📦 Main Inventory</h3>
          <p style="color:#666;margin-top:0;">Current quantities are grouped by section. Medical supplies at 15 or less are flagged as low stock.</p>
          <div class="inventory-category-nav" aria-label="Inventory categories">
            <button type="button" class="inventory-category-btn restock-btn<?php echo $selected_inventory_category === 'Restock' ? ' active' : ''; ?>" data-inventory-category="Restock" onclick="selectInventoryCategory('Restock')">⚠️ Medical Supplies Requiring Restock<?php if ($medical_low_items): ?> <span class="restock-count"><?php echo count($medical_low_items); ?></span><?php endif; ?></button>
            <button type="button" class="inventory-category-btn exp-btn<?php echo $selected_inventory_category === 'Expiry' ? ' active' : ''; ?>" data-inventory-category="Expiry" onclick="selectInventoryCategory('Expiry')">🗓 Expiry Tracking<?php if ($exp_alerts || $exp['total']['unset']): ?> <span class="restock-count" title="Batches expired (waiting for restock) or expiring within 3 months"><?php echo count($exp_open) + count(array_filter($exp_list, function ($b) { $st = exp_status($b['expiry_date'])[0]; return $st === 'expired' || $st === 'soon'; })); ?></span><?php endif; ?></button>
            <button type="button" class="inventory-category-btn<?php echo $selected_inventory_category === 'Tools & Equipment' ? ' active' : ''; ?>" data-inventory-category="Tools & Equipment" onclick="selectInventoryCategory('Tools & Equipment')">1. Tools & Equipment</button>
            <button type="button" class="inventory-category-btn<?php echo $selected_inventory_category === 'Medical Supplies' ? ' active' : ''; ?>" data-inventory-category="Medical Supplies" onclick="selectInventoryCategory('Medical Supplies')">2. Medical Supplies</button>
            <?php if (!empty($inventory_by_section['Other Items'])): ?><button type="button" class="inventory-category-btn<?php echo $selected_inventory_category === 'Other Items' ? ' active' : ''; ?>" data-inventory-category="Other Items" onclick="selectInventoryCategory('Other Items')">3. Other Items (<?php echo count($inventory_by_section['Other Items']); ?>)</button><?php endif; ?>
          </div>
          <div class="inventory-alert-section inventory-section<?php echo $selected_inventory_category === 'Restock' ? '' : ' inventory-hidden'; ?>" data-inventory-section="Restock">
            <h4>⚠️ Medical Supplies Requiring Restock</h4>
            <?php if ($medical_low_items): ?>
              <div class="table-wrapper">
                <table class="table">
                  <thead><tr><th>ITEM</th><th>CURRENT UNITS</th><th>REQUIRED</th><th>STATUS</th><th>ACTION</th></tr></thead>
                  <tbody>
                    <?php foreach ($medical_low_items as $low_item): ?>
                      <tr>
                        <td><strong><?php echo e($low_item['item_description']); ?></strong></td>
                        <td><?php echo (int)$low_item['current_stock']; ?></td>
                        <td><?php echo (int)$low_item['required_quantity']; ?></td>
                        <td class="low-stock-status">Low Stock</td>
                        <td><button type="button" class="btn btn-small" onclick="selectInventoryCategory('Medical Supplies')">Update</button></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="inventory-alert-empty">All medical supplies are above the low-stock threshold.</div>
            <?php endif; ?>
          </div>
          <div class="inventory-section exp-section<?php echo $selected_inventory_category === 'Expiry' ? '' : ' inventory-hidden'; ?>" data-inventory-section="Expiry">
            <h4>🗓 Expiry Tracking <small>(medical supplies, by batch)</small></h4>
            <p class="exp-help">Each delivery or Item In of a medical supply is a batch with its own expiry date. Item Out always takes from the batch that expires first (<b>FEFO</b>: First Expired, First Out). You are alerted <b>3 months</b> before a batch expires and again <b>1 month</b> before. <b>On its expiry date, the batch is removed from your stock automatically</b> (it no longer counts in your inventory, reports or forecast) and recorded in the Audit Logs.</p>
            <div class="exp-cards">
              <div class="exp-card expired"><b><?php echo (int)$exp['total']['removed']; ?></b><span>units expired &middot; removed, waiting for restock</span></div>
              <div class="exp-card soon"><b><?php echo (int)$exp['total']['soon']; ?></b><span>units expiring within 3 months</span></div>
              <div class="exp-card unset"><b><?php echo (int)$exp['total']['unset']; ?></b><span>units with no expiry date yet</span></div>
              <div class="exp-card ok"><b><?php echo (int)$exp['total']['ok']; ?></b><span>units good for more than 3 months</span></div>
            </div>
            <?php if (!$exp_list && !$exp_open): ?><div class="inventory-alert-empty">No medical supplies in stock.</div><?php else: ?>
            <div class="table-wrapper"><table class="table exp-table">
              <thead><tr><th>ITEM</th><th>BATCH</th><th>QTY</th><th>EXPIRY DATE</th><th>STATUS</th><th>ACTION</th></tr></thead>
              <tbody>
              <?php foreach ($exp_open as $xb): ?>
                <tr class="exp-row-expired exp-row-gone">
                  <td><strong><?php echo e($xb['item_name']); ?></strong></td>
                  <td><small>#<?php echo (int)$xb['id']; ?><?php echo $xb['lot_no'] ? ' &middot; lot ' . e($xb['lot_no']) : ''; ?><br>removed <?php echo e(date('M j, Y', strtotime($xb['disposed_at']))); ?> &middot; <?php echo e((string)$xb['disposed_ref']); ?></small></td>
                  <td>0 <small>(<?php echo (int)$xb['disposed_qty']; ?> removed)</small></td>
                  <td><?php echo e(date('M j, Y', strtotime($xb['expiry_date']))); ?></td>
                  <td><span class="exp-pill expired">⛔ Expired &middot; removed from stock</span></td>
                  <td><small class="exp-auto">🔒 Restock this item to clear</small></td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($exp_list as $bt): [$est, $edays, $elabel] = exp_status($bt['expiry_date']);
                $src = ['item_in' => 'Item In', 'delivery' => 'Delivery', 'report' => 'Report', 'opening' => 'Starting stock'][$bt['source']] ?? $bt['source']; ?>
                <tr class="exp-row-<?php echo e($est); ?>">
                  <td><strong><?php echo e($bt['item_name']); ?></strong></td>
                  <td><small>#<?php echo (int)$bt['id']; ?> &middot; <?php echo e($src); ?><?php echo $bt['lot_no'] ? ' &middot; lot ' . e($bt['lot_no']) : ''; ?><br>added <?php echo e(date('M j, Y', strtotime($bt['created_at']))); ?></small></td>
                  <td><?php echo (int)$bt['qty_left']; ?><?php if ((int)$bt['qty_left'] !== (int)$bt['qty_in']): ?><small> of <?php echo (int)$bt['qty_in']; ?></small><?php endif; ?></td>
                  <td><?php echo $bt['expiry_date'] ? e(date('M j, Y', strtotime($bt['expiry_date']))) : '<span style="color:#98a2b3;">Not set</span>'; ?></td>
                  <td><span class="exp-pill <?php echo e($est); ?>"><?php echo e($est === 'ok' ? 'Good' : $elabel); ?></span></td>
                  <td>
                    <?php if ($est === 'expired'): ?>
                      <small class="exp-auto">⏳ Removed from stock automatically</small>
                    <?php else: ?>
                      <details class="exp-edit"><summary><?php echo $est === 'unset' ? 'Set expiry date' : 'Edit date'; ?></summary>
                        <form method="post" class="exp-act exp-fields exp-inline"><input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="date" name="expiry_date" class="exp-date" required min="<?php echo e(exp_min_date()); ?>" value="<?php echo e((string)$bt['expiry_date']); ?>"><input type="text" name="lot_no" class="exp-lot" maxlength="60" placeholder="Lot no." value="<?php echo e((string)$bt['lot_no']); ?>"><?php echo exp_scan_control('📷 Scan'); ?><button class="btn btn-small" name="exp_set" value="<?php echo (int)$bt['id']; ?>">Save</button><p class="exp-scan-msg" aria-live="polite"></p></form>
                      </details>
                      <?php if ($est === 'soon'): ?><small class="exp-first" title="FEFO: First Expired, First Out. Item Out takes this batch before the others.">⬆ Goes out first</small><?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php endif; ?>
          </div>
          <?php if (empty($inventory_by_section)): ?>
            <p style="color:#666;">No inventory items have been added yet.</p>
          <?php else: ?>
            <?php foreach ($inventory_by_section as $section => $items): ?>
              <div class="inventory-section<?php echo $section === $selected_inventory_category ? '' : ' inventory-hidden'; ?>" data-inventory-section="<?php echo e($section); ?>">
                <h4><?php echo e($section); ?></h4>
                <?php if ($section === 'Medical Supplies'): ?>
                  <div class="medical-bulk-actions">
                    <button type="button" class="btn btn-small" onclick="saveAllMedicalSupplies()">Save All Medical Supplies</button>
                  </div>
                <?php endif; ?>
                <div class="table-wrapper">
                  <table class="table">
                    <thead><tr><th>ITEM</th><th>STATUS</th><th>QUANTITY</th><th><?php echo in_array($section, ['Tools & Equipment', 'Medical Supplies'], true) ? 'ITEM OUT / ITEM IN' : 'SAVE'; ?></th></tr></thead>
                    <tbody>
                      <?php foreach ($items as $item): ?>
                        <?php
                          $is_supply = $section === 'Medical Supplies';
                          $is_low = $is_supply && (int)$item['current_stock'] <= 15;
                          $vehicle_status = in_array($item['status'] ?? '', ['Operational', 'Under Maintenance', 'Not Operational'], true) ? $item['status'] : 'Operational';
                        ?>
                        <tr<?php echo $is_supply ? ' data-medical-row="1"' : ''; ?>>
                          <td>
                            <strong><?php echo e($item['item_description']); ?></strong>
                            <?php if ($section === 'Medical Supplies' && !empty($item['field']) && isset($exp['items'][$item['field']])): $ei = $exp['items'][$item['field']]; ?>
                              <br><a href="#" class="exp-line" onclick="selectInventoryCategory('Expiry');return false;" title="Open Expiry Tracking">🗓 <?php
                                if (!empty($ei['removed'])) echo '<b class="x">' . (int)$ei['removed'] . ' expired, removed &middot; restock needed</b> &middot; ';
                                if ($ei['expired']) echo '<b class="x">' . (int)$ei['expired'] . ' expired</b> &middot; ';
                                if ($ei['soon']) echo '<b class="s">' . (int)$ei['soon'] . ' expiring soon</b> &middot; ';
                                echo $ei['nearest'] ? 'nearest expiry ' . e(date('M j, Y', strtotime($ei['nearest']))) : 'expiry not set';
                                if ($ei['unset'] && $ei['nearest']) echo ' &middot; ' . (int)$ei['unset'] . ' not set';
                                echo ' &middot; ' . (int)$ei['batches'] . ' batch' . ($ei['batches'] === 1 ? '' : 'es'); ?></a>
                            <?php endif; ?>
                            <?php if ($section !== 'Medical Supplies' && !empty($item['notes'])): ?>
                              <br><small><strong>Remarks:</strong> <?php echo e($item['notes']); ?></small>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if (!empty($item['virtual']) && (int)$item['current_stock'] === 0): ?>
                              <span class="badge badge-low-stock">Out of Stock</span>
                            <?php elseif (!empty($item['virtual']) && $is_supply && $is_low): ?>
                              <span class="badge badge-low-stock">Low Stock / Restock Needed</span>
                            <?php elseif (!empty($item['virtual'])): ?>
                              <span class="badge badge-verified"><?php echo e($section === 'Vehicles' ? 'Operational' : 'Available'); ?></span>
                            <?php elseif ($is_supply): ?>
                              <span class="badge <?php echo $is_low ? 'badge-low-stock' : 'badge-verified'; ?>"><?php echo $is_low ? 'Low Stock / Restock Needed' : 'Verified'; ?></span>
                            <?php elseif ($section === 'Vehicles'): ?>
                              <span class="badge <?php echo $vehicle_status === 'Operational' ? 'badge-verified' : 'badge-pending'; ?>"><?php echo e($vehicle_status); ?></span>
                            <?php else: ?>
                              <span class="badge badge-verified">Available</span>
                            <?php endif; ?>
                          </td>
                          <td class="<?php echo $is_low ? 'low-stock' : ''; ?>"><?php echo (int)$item['current_stock']; ?></td>
                          <td>
                            <?php if (!empty($item['virtual'])): ?>
                              <?php if (in_array($section, ['Tools & Equipment', 'Medical Supplies'], true) && !empty($item['field']) && $inv_report): ?>
                                <div class="inv-tx">
                                  <button type="button" class="out" data-type="released" data-item="<?php echo e($item['field']); ?>" data-name="<?php echo e($item['item_description']); ?>" data-qty="<?php echo (int)$item['current_stock']; ?>" data-cat="<?php echo e($section); ?>"<?php echo (int)$item['current_stock'] < 1 ? ' disabled title="Out of stock"' : ''; ?> title="Item Out: items taken out of stock">&#8593; Item Out</button>
                                  <button type="button" class="in" data-type="obtained" data-item="<?php echo e($item['field']); ?>" data-name="<?php echo e($item['item_description']); ?>" data-qty="<?php echo (int)$item['current_stock']; ?>" data-cat="<?php echo e($section); ?>" title="Item In: items returned or brought in">&#8595; Item In</button>
                                </div>
                              <?php else: ?>
                                <small style="color:#777;">Update it in Submit Report.</small>
                              <?php endif; ?>
                            <?php else: ?>
                            <form method="post" class="inventory-save">
                              <input type="hidden" name="save_inventory_item" value="1">
                              <input type="hidden" name="inventory_category" value="<?php echo e($selected_inventory_category); ?>">
                              <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                              <input type="number" name="current_stock" min="0" value="<?php echo (int)$item['current_stock']; ?>" aria-label="Quantity for <?php echo e($item['item_description']); ?>">
                              <?php if ($section === 'Vehicles'): ?>
                                <select name="vehicle_status" aria-label="Status for <?php echo e($item['item_description']); ?>">
                                  <?php foreach (['Operational', 'Under Maintenance', 'Not Operational'] as $status_option): ?>
                                    <option value="<?php echo e($status_option); ?>"<?php echo $vehicle_status === $status_option ? ' selected' : ''; ?>><?php echo e($status_option); ?></option>
                                  <?php endforeach; ?>
                                </select>
                              <?php elseif ($section === 'Tools & Equipment'): ?>
                                <input type="text" name="inventory_notes" value="<?php echo e($item['notes'] ?? ''); ?>" placeholder="Remarks (e.g. broken)" aria-label="Remarks for <?php echo e($item['item_description']); ?>">
                              <?php endif; ?>
                              <button type="submit" class="btn btn-small">Save</button>
                            </form>
                            <?php if ($is_supply): ?>
                              <form method="post" class="inventory-save" style="margin-top:6px;">
                                <input type="hidden" name="add_inventory_item" value="1">
                                <input type="hidden" name="inventory_category" value="<?php echo e($selected_inventory_category); ?>">
                                <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                <button type="submit" class="btn btn-small inventory-add">Add 1</button>
                              </form>
                              <form method="post" class="inventory-save" style="margin-top:6px;">
                                <input type="hidden" name="use_inventory_item" value="1">
                                <input type="hidden" name="inventory_category" value="<?php echo e($selected_inventory_category); ?>">
                                <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                <button type="submit" class="btn btn-small inventory-use"<?php echo (int)$item['current_stock'] === 0 ? ' disabled' : ''; ?>>Use 1</button>
                              </form>
                            <?php endif; ?>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
        </div>

        <section class="preparedness-modal<?php echo $show_preparedness_analysis ? ' active' : ''; ?>" id="preparednessAnalysisModal" onclick="closePreparednessAnalysis(event)" aria-hidden="<?php echo $show_preparedness_analysis ? 'false' : 'true'; ?>">
          <div class="card preparedness-modal-content" onclick="event.stopPropagation()">
          <h3 class="preparedness-modal-header" style="background:var(--primary);">📈 Disaster Preparedness Analysis <button type="button" class="preparedness-modal-close" onclick="closePreparednessAnalysis()" aria-label="Close Disaster Preparedness Analysis">&times;</button></h3>
          <p style="color:#666;margin-top:0;">This readiness estimate compares your current stock with required quantities for the selected disaster type.</p>
          <div class="preparedness-panel">
            <div class="preparedness-score">
              <form method="get">
                <input type="hidden" name="inventory_view" value="preparedness">
                <label for="hazard">Select disaster type</label>
                <select id="hazard" name="hazard" onchange="this.form.submit()">
                  <?php foreach ($hazard_options as $hazard_key => $hazard_option): ?>
                    <option value="<?php echo e($hazard_key); ?>"<?php echo $selected_hazard === $hazard_key ? ' selected' : ''; ?>><?php echo e($hazard_option['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <strong><?php echo $hazard_score; ?>%</strong>
              <span><?php echo e($hazard['label']); ?> preparedness score</span>
              <p class="preparedness-note">
                <?php if ($hazard_score < 50): ?>Prioritize restocking and checking the highlighted equipment before deployment.
                <?php elseif ($hazard_score < 80): ?>Stock is partly ready. Review low quantities and complete equipment checks.
                <?php else: ?>Stock is in good shape for this scenario. Continue routine checks and replenishment.
                <?php endif; ?>
              </p>
            </div>
            <div class="preparedness-chart">
              <?php if (!$hazard_items): ?>
                <p style="color:#666;">Add or update inventory items to generate the preparedness graph.</p>
              <?php else: ?>
                <?php foreach ($hazard_items as $hazard_item): ?>
                  <div class="preparedness-row">
                    <div class="preparedness-row-head"><span><?php echo e($hazard_item['name']); ?></span><strong><?php echo $hazard_item['readiness']; ?>%</strong></div>
                    <div class="preparedness-bar" aria-label="<?php echo e($hazard_item['name']); ?> readiness <?php echo $hazard_item['readiness']; ?> percent"><span style="width:<?php echo $hazard_item['readiness']; ?>%;"></span></div>
                    <small style="color:#777;">Current: <?php echo $hazard_item['current']; ?><?php echo $hazard_item['required'] > 0 ? ' / Required: ' . $hazard_item['required'] : ''; ?></small>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          </div>
        </section>

        <div class="dash-panel" data-dash-panel="inventory">
        <section class="card" id="equipment-update-panel" style="margin:0;">
          <h3 style="background:var(--primary);">
            📊 Update Equipment Inventory
          </h3>

          <!-- Step Indicator -->
          <div class="step-indicator">
            <div class="step">
              <button type="button" class="step-dot active" id="step1-dot" onclick="selectStep(1)" aria-label="Start">1</button>
              <div class="step-label active" id="step1-label">Start</div>
            </div>
            <div style="width:30px;height:2px;background:#ecf0f1;margin-top:16px;"></div>
            <div class="step">
              <button type="button" class="step-dot" id="step2-dot" onclick="selectStep(2)" aria-label="Open Tools and Equipment">2</button>
              <div class="step-label" id="step2-label">Tools & Equipment</div>
            </div>
            <div style="width:30px;height:2px;background:#ecf0f1;margin-top:16px;"></div>
            <div class="step">
              <button type="button" class="step-dot" id="step3-dot" onclick="selectStep(3)" aria-label="Open Medical Supplies">3</button>
              <div class="step-label" id="step3-label">Medical Supplies</div>
            </div>
          </div>

          <form method="POST" id="updateForm">
            <!-- STEP 1: START -->
            <div class="form-section active" id="section1">
              <div class="section-header">
                📋 Step 1: Start
              </div>
              <p style="color:#667085;margin:0;">Update your tools, equipment and medical supplies in the next steps.</p>
            </div>

            <!-- STEP 2: TOOLS & EQUIPMENT -->
            <div class="form-section" id="section2">
              <div class="section-header">
                🔧 Step 2: Tools & Equipment
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Portable Oxygen (Always Check Before Use)</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="portable_oxygen" min="0" value="<?php echo e($equipment_report['portable_oxygen'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="portable_oxygen_remarks" placeholder="e.g., All IN - Working, or Out of Stock" style="resize:vertical;"><?php echo e($equipment_report['portable_oxygen_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Portable Oxygen</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Stretcher / Scoop</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="stretcher_scoop" min="0" value="<?php echo e($equipment_report['stretcher_scoop'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="stretcher_scoop_remarks" placeholder="e.g., All IN - Working, or Out of Stock" style="resize:vertical;"><?php echo e($equipment_report['stretcher_scoop_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Stretcher / Scoop</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Spider Strap</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="spider_strap" min="0" value="<?php echo e($equipment_report['spider_strap'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="spider_strap_remarks" placeholder="e.g., All IN - Working, or Out of Stock" style="resize:vertical;"><?php echo e($equipment_report['spider_strap_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Spider Strap</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">BP Automatic / Manual</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="bp_automatic" min="0" value="<?php echo e($equipment_report['bp_automatic'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="bp_automatic_remarks" placeholder="e.g., All IN - Working, or Out of Stock" style="resize:vertical;"><?php echo e($equipment_report['bp_automatic_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save BP Equipment</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Pulse Oximeter</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="pulse_oximeter" min="0" value="<?php echo e($equipment_report['pulse_oximeter'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="pulse_oximeter_remarks" placeholder="e.g., All IN - Working, or Out of Stock" style="resize:vertical;"><?php echo e($equipment_report['pulse_oximeter_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Pulse Oximeter</button>
                </div>
              </div>
            </div>

            <!-- STEP 3: MEDICAL SUPPLIES -->
            <div class="form-section" id="section3">
              <div class="section-header">
                💊 Step 3: Medical Supplies
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Facemask</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="facemask" min="0" value="<?php echo e($equipment_report['facemask'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="facemask_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['facemask_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Facemask</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Elastic Bandage</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="elastic_bandage" min="0" value="<?php echo e($equipment_report['elastic_bandage'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="elastic_bandage_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['elastic_bandage_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Elastic Bandage</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Gloves</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="gloves" min="0" value="<?php echo e($equipment_report['gloves'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="gloves_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['gloves_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Gloves</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Betadine Dropper</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="betadine_dropper" min="0" value="<?php echo e($equipment_report['betadine_dropper'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="betadine_dropper_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['betadine_dropper_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Betadine Dropper</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Surgical Tape</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="surgical_tape" min="0" value="<?php echo e($equipment_report['surgical_tape'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="surgical_tape_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['surgical_tape_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Surgical Tape</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Gauze Pad</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="gauze_pad" min="0" value="<?php echo e($equipment_report['gauze_pad'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="gauze_pad_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['gauze_pad_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Gauze Pad</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Nasal Cannula</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="nasal_cannula" min="0" value="<?php echo e($equipment_report['nasal_cannula'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="nasal_cannula_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['nasal_cannula_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Nasal Cannula</button>
                </div>
              </div>

              <div class="equipment-item">
                <div class="equipment-item-label">Oxygen Mask</div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="oxygen_mask" min="0" value="<?php echo e($equipment_report['oxygen_mask'] ?? ''); ?>" placeholder="Enter quantity">
                  </div>
                  <div class="form-group">
                    <label>Status & Remarks</label>
                    <textarea name="oxygen_mask_remarks" placeholder="e.g., IN - Good condition, or OUT of Stock" style="resize:vertical;"><?php echo e($equipment_report['oxygen_mask_remarks'] ?? ''); ?></textarea>
                  </div>
                  <button type="submit" name="save_inventory" value="1" class="btn btn-small equipment-save">Save Oxygen Mask</button>
                </div>
              </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
              <button type="button" class="btn-back" id="backBtn" onclick="previousStep()" style="display:none;">← Back</button>
              <button type="button" class="btn-next" id="nextBtn" onclick="nextStep()">Next →</button>
              <button type="submit" class="btn-submit" id="submitBtn" name="save_inventory" value="1" style="display:none;">✓ Save Updated Inventory</button>
            </div>
          </form>
        </section>
        </div>

        <div class="dash-panel" data-dash-panel="maps" id="dashPanelMaps" role="tabpanel" aria-labelledby="dashTabMaps" hidden>
          <section class="card dash-map-card">
            <h3 style="background:var(--primary);"><span>🗺️ Barangay Maps</span><button type="button" class="dash-map-full" id="dashMapFull" title="Show the map on the whole screen">⛶ Full screen</button></h3>
            <p class="dash-map-sub">Live South Caloocan barangay report map. Zoom and drag to explore; tap a barangay for its status.</p>
            <!-- Loaded the first time the Maps button is opened, so the inventory view stays fast. -->
            <iframe class="dash-map-frame" id="dashMapFrame" data-src="user_map.php?embed=1" title="South Caloocan Barangay Map"></iframe>
          </section>
        </div>
      </main>

      <!-- Floating buttons (bottom-right): each opens its own small panel, so the inventory and map get the full width. -->
      <div class="float-dock" id="floatDock">
        <?php
        // Same list the Main Inventory table flags (this barangay's medical supplies at 15 or fewer),
        // so the alert count always matches the "low" badge on the Main Inventory button.
        $low_items = $medical_low_items;
        $low_count = count($low_items);
        ?>

        <div class="float-panel" id="floatPanelAlerts" role="dialog" aria-label="Low stock alerts" hidden>
        <button type="button" class="float-panel-close" data-float-close aria-label="Close">&times;</button>
        <div class="info-card alert-danger" style="border-left:4px solid #e74c3c;">
          <h4>⚠️ Low Stock Alerts (<?php echo $low_count; ?>)</h4>
          <?php if ($low_count > 0): ?>
            <ul style="padding-left:18px;color:#333;margin-top:8px;">
              <?php foreach($low_items as $index => $it): ?>
                <li class="low-stock-item" data-low-stock-index="<?php echo $index; ?>" style="margin-bottom:8px;<?php echo $index >= 5 ? 'display:none;' : ''; ?>"><strong><?php echo e($it['item_description']); ?></strong> — <?php echo (int)$it['current_stock']; ?> left (required: <?php echo (int)$it['required_quantity']; ?>)</li>
              <?php endforeach; ?>
            </ul>
            <?php if ($low_count > 5): ?>
              <button type="button" class="btn btn-small low-stock-more" id="lowStockMore" onclick="showAllLowStock()">More low stock (<?php echo $low_count - 5; ?>)</button>
            <?php endif; ?>
            <p style="margin-top:8px;color:#666;font-size:13px;">Please request restock or notify your admin.</p>
          <?php else: ?>
            <p style="color:#666;margin-top:6px;">All items are above the low-stock threshold.</p>
          <?php endif; ?>
        </div>
        </div>

        <?php if ($approved_training_schedules): ?>
          <div class="float-panel" id="floatPanelTraining" role="dialog" aria-label="Approved training and drills" hidden>
          <button type="button" class="float-panel-close" data-float-close aria-label="Close">&times;</button>
          <div class="info-card approved-training-card">
            <h4>📚 Approved Training &amp; Drills</h4>
            <?php foreach ($approved_training_schedules as $schedule): ?>
              <div class="approved-training-item">
                <strong><?php echo e($schedule['training_name']); ?></strong>
                <span><?php echo e(training_when_text($schedule, 'F j, Y')); ?></span>
                <span><?php echo e($schedule['location']); ?></span>
                <small><?php echo e(training_participants_text($schedule['participants'])); ?> · <?php echo e(training_duration_short($schedule)); ?></small>
                <button type="button" class="approved-training-read" onclick="openTrainingDetails(<?php echo e(json_encode([
                  'name' => $schedule['training_name'],
                  'date' => training_when_text($schedule, 'F j, Y'),
                  'location' => $schedule['location'],
                  'scope' => $schedule['scope'],
                  'participants' => training_participants_text($schedule['participants']),
                  'duration' => training_duration_short($schedule),
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)); ?>)">Read details</button>
              </div>
            <?php endforeach; ?>
          </div>
          </div>
        <?php endif; ?>

        <div class="float-panel" id="floatPanelAds" role="dialog" aria-label="Advertisements" hidden>
        <button type="button" class="float-panel-close" data-float-close aria-label="Close">&times;</button>
        <div class="contacts-card advertisement-card" id="advertisementSidePanel">
          <h4>📣 Advertisements</h4>
          <?php if ($advertisements): ?>
            <div class="advertisement-carousel" id="advertisementCarousel">
              <?php foreach ($advertisements as $advertisement_index => $advertisement): ?>
                <div class="advertisement-slide<?php echo $advertisement_index === 0 ? ' active' : ''; ?>" data-advertisement-slide>
                  <p><?php echo e($advertisement['title']); ?></p>
                  <img class="advertisement-photo" src="<?php echo e($advertisement['image_path']); ?>" alt="<?php echo e($advertisement['title']); ?>" role="button" tabindex="0" title="Click to enlarge" onclick="zoomAdvertisement(<?php echo (int)$advertisement_index; ?>)" onkeydown="if (event.key === 'Enter') zoomAdvertisement(<?php echo (int)$advertisement_index; ?>)">
                </div>
              <?php endforeach; ?>
              <?php if (count($advertisements) > 1): ?>
                <div class="advertisement-nav" aria-label="Advertisement controls">
                  <button type="button" onclick="changeAdvertisement(-1)" aria-label="Previous advertisement">&lsaquo;</button>
                  <button type="button" onclick="changeAdvertisement(1)" aria-label="Next advertisement">&rsaquo;</button>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p>No advertisements posted yet.</p>
          <?php endif; ?>
        </div>
        </div>

        <div class="float-buttons">
          <button type="button" class="float-btn<?php echo $low_count ? ' is-alert' : ''; ?>" data-float-open="floatPanelAlerts" aria-controls="floatPanelAlerts" aria-expanded="false" title="Low stock alerts">
            <span aria-hidden="true">⚠️</span><span class="float-btn-label">Low stock alerts</span>
            <?php if ($low_count): ?><span class="float-badge"><?php echo $low_count; ?></span><?php endif; ?>
          </button>
          <?php if ($approved_training_schedules): ?>
          <button type="button" class="float-btn" data-float-open="floatPanelTraining" aria-controls="floatPanelTraining" aria-expanded="false" title="Approved training &amp; drills">
            <span aria-hidden="true">📚</span><span class="float-btn-label">Approved training &amp; drills</span>
            <span class="float-badge"><?php echo count($approved_training_schedules); ?></span>
          </button>
          <?php endif; ?>
          <button type="button" class="float-btn" data-float-open="floatPanelAds" aria-controls="floatPanelAds" aria-expanded="false" title="Advertisements">
            <span aria-hidden="true">📣</span><span class="float-btn-label">Advertisements</span>
            <?php if ($advertisements): ?><span class="float-badge"><?php echo count($advertisements); ?></span><?php endif; ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="training-details-modal" id="trainingDetailsModal" onclick="closeTrainingDetails(event)" aria-hidden="true">
  <div class="training-details-content" onclick="event.stopPropagation()">
    <div class="training-details-header">
      <h3 id="trainingDetailsTitle">Training details</h3>
      <button type="button" class="training-details-close" onclick="closeTrainingDetails()" aria-label="Close training details">&times;</button>
    </div>
    <div class="training-details-body">
      <p><strong>Schedule:</strong> <span id="trainingDetailsDate"></span></p>
      <p><strong>Location:</strong> <span id="trainingDetailsLocation"></span></p>
      <p><strong>Scope:</strong> <span id="trainingDetailsScope"></span></p>
      <p><strong>Participants:</strong> <span id="trainingDetailsParticipants"></span></p>
      <p><strong>Duration:</strong> <span id="trainingDetailsDuration"></span></p>
    </div>
  </div>
</div>


<div class="contact-chat-modal" id="contactChatModal" onclick="closeContactChat(event)">
  <div class="contact-chat" onclick="event.stopPropagation()">
    <div class="contact-chat-header">
      <strong>💬 Contact Admin</strong>
      <button type="button" class="contact-chat-close" onclick="closeContactChat()" aria-label="Close chat">&times;</button>
    </div>
    <div class="contact-chat-messages">
      <?php if (empty($chat_messages)): ?>
        <p style="color:#666;font-size:13px;margin:0;">Send a message to communicate with the admin.</p>
      <?php else: ?>
        <?php foreach ($chat_messages as $chat_message): ?>
          <div class="contact-chat-message <?php echo $chat_message['sender_role'] === 'user' ? 'user' : 'admin'; ?>">
            <?php echo nl2br(e($chat_message['message'])); ?>
            <small><?php echo $chat_message['sender_role'] === 'user' ? 'You' : 'Admin'; ?> · <?php echo e($chat_message['created_at']); ?></small>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <form method="post">
      <input type="hidden" name="send_contact_message" value="1">
      <textarea name="message" placeholder="Write a message to the admin..." required></textarea>
      <button type="submit" class="btn" style="width:100%;">Send Message</button>
    </form>
  </div>
</div>

<script>
</script>
<script>
// Main Inventory / Barangay Maps buttons: one view at a time. The choice survives a refresh (per tab session).
(function () {
  const tabs = Array.prototype.slice.call(document.querySelectorAll('.dash-tab'));
  const mapFrame = document.getElementById('dashMapFrame');
  function showDashTab(name, focus) {
    tabs.forEach(function (tab) {
      const on = tab.dataset.dashTab === name;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    document.querySelectorAll('.dash-panel').forEach(function (panel) { panel.hidden = panel.dataset.dashPanel !== name; });
    if (name === 'maps' && mapFrame && !mapFrame.getAttribute('src')) mapFrame.setAttribute('src', mapFrame.dataset.src);
    try { sessionStorage.setItem('dashTab', name); } catch (err) {}
    const main = document.getElementById('dashboard-main');
    if (main) main.scrollTop = 0;
  }
  window.showDashTab = showDashTab;
  tabs.forEach(function (tab, i) {
    tab.tabIndex = tab.classList.contains('active') ? 0 : -1;
    tab.addEventListener('click', function () { showDashTab(tab.dataset.dashTab, false); });
    tab.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
      showDashTab(tabs[(i + 1) % tabs.length].dataset.dashTab, true);
    });
  });
  let saved = null;
  try { saved = sessionStorage.getItem('dashTab'); } catch (err) {}
  // After an inventory update the page stays on Main Inventory; otherwise the last tab used is restored.
  if (<?php echo $dash_default === 'overview' ? 'true' : 'false'; ?> && (saved === 'maps' || saved === 'inventory')) showDashTab(saved, false);

  // Full screen map (Esc returns to the dashboard).
  const fullBtn = document.getElementById('dashMapFull');
  if (fullBtn && mapFrame) fullBtn.addEventListener('click', function () {
    if (!mapFrame.getAttribute('src')) mapFrame.setAttribute('src', mapFrame.dataset.src);
    const req = mapFrame.requestFullscreen || mapFrame.webkitRequestFullscreen;
    if (req) req.call(mapFrame);
  });
})();

// Floating buttons: open one panel at a time; click the same button, the ×, outside, or press Esc to close.
(function () {
  const dock = document.getElementById('floatDock');
  if (!dock) return;
  const buttons = Array.prototype.slice.call(dock.querySelectorAll('[data-float-open]'));
  function closeAll() {
    dock.querySelectorAll('.float-panel').forEach(function (p) { p.hidden = true; });
    buttons.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
  }
  buttons.forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.stopPropagation();
      const panel = document.getElementById(btn.dataset.floatOpen);
      const wasOpen = panel && !panel.hidden;
      closeAll();
      if (panel && !wasOpen) {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        const close = panel.querySelector('[data-float-close]');
        if (close) close.focus();
      }
    });
  });
  dock.querySelectorAll('[data-float-close]').forEach(function (c) {
    c.addEventListener('click', function () {
      const panel = c.closest('.float-panel');
      const opener = buttons.find(function (b) { return b.dataset.floatOpen === panel.id; });
      closeAll();
      if (opener) opener.focus();
    });
  });
  document.addEventListener('click', function (event) {
    if (!dock.contains(event.target) && !event.target.closest('.sb-ad-modal, .training-details-modal')) closeAll();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && dock.querySelector('.float-panel:not([hidden])')) closeAll();
  });
})();
</script>

<script>
let currentStep = 1;
let currentAdvertisement = 0;

// Click an ad in the side panel to see it enlarged in the same popup the sidebar button opens.
function zoomAdvertisement(index) {
  if (typeof window.drrmcOpenAd === 'function') window.drrmcOpenAd(index);
}

function changeAdvertisement(direction) {
  const slides = document.querySelectorAll('[data-advertisement-slide]');
  if (slides.length < 2) return;
  currentAdvertisement = (currentAdvertisement + direction + slides.length) % slides.length;
  slides.forEach(function(slide, index) {
    slide.classList.toggle('active', index === currentAdvertisement);
  });
}

function openTrainingDetails(training) {
  const modal = document.getElementById('trainingDetailsModal');
  if (!modal) return;
  document.getElementById('trainingDetailsTitle').textContent = training.name;
  document.getElementById('trainingDetailsDate').textContent = training.date;
  document.getElementById('trainingDetailsLocation').textContent = training.location;
  document.getElementById('trainingDetailsScope').textContent = training.scope;
  document.getElementById('trainingDetailsParticipants').textContent = training.participants;
  document.getElementById('trainingDetailsDuration').textContent = training.duration;
  modal.classList.add('active');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

function closeTrainingDetails(event) {
  if (event && event.target !== event.currentTarget) return;
  const modal = document.getElementById('trainingDetailsModal');
  if (!modal) return;
  modal.classList.remove('active');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

function openContactChat(event) {
  if (event) event.preventDefault();
  document.getElementById('contactChatModal').classList.add('active');
}

function closeContactChat(event) {
  if (event && event.target !== event.currentTarget) return;
  document.getElementById('contactChatModal').classList.remove('active');
}

function openPreparednessAnalysis() {
  const modal = document.getElementById('preparednessAnalysisModal');
  modal.classList.add('active');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

function closePreparednessAnalysis(event) {
  if (event && event.target !== event.currentTarget) return;
  const modal = document.getElementById('preparednessAnalysisModal');
  modal.classList.remove('active');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

function selectInventoryCategory(category) {
  let selectedSection = null;
  document.querySelectorAll('[data-inventory-section]').forEach(function(section) {
    const isSelected = section.dataset.inventorySection === category;
    section.classList.toggle('inventory-hidden', !isSelected);
    if (isSelected) selectedSection = section;
  });
  document.querySelectorAll('[data-inventory-category]').forEach(function(button) {
    button.classList.toggle('active', button.dataset.inventoryCategory === category);
  });
  document.querySelectorAll('input[name="inventory_category"]').forEach(function(input) {
    input.value = category;
  });
  if (selectedSection) selectedSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showAllLowStock() {
  document.querySelectorAll('.low-stock-item').forEach(function(item) {
    item.style.display = '';
  });
  const moreButton = document.getElementById('lowStockMore');
  if (moreButton) moreButton.style.display = 'none';
}

function nextStep() {
  // Validate current step before proceeding
  if (!validateStep(currentStep)) {
    alert('❌ Please fill in all required fields before proceeding!');
    return;
  }

  if (currentStep < 3) {
    document.getElementById('section' + currentStep).classList.remove('active');
    currentStep++;
    document.getElementById('section' + currentStep).classList.add('active');
    updateStepIndicator();
  }
}

function selectStep(step) {
  if (step < 1 || step > 3 || step === currentStep) return;

  document.getElementById('section' + currentStep).classList.remove('active');
  currentStep = step;
  document.getElementById('section' + currentStep).classList.add('active');
  updateStepIndicator();
}

function previousStep() {
  if (currentStep > 1) {
    document.getElementById('section' + currentStep).classList.remove('active');
    currentStep--;
    document.getElementById('section' + currentStep).classList.add('active');
    updateStepIndicator();
  }
}

function validateStep(step) {
  // No step has required fields.
  return true;
}

function updateStepIndicator() {
  // Update step dots
  for (let i = 1; i <= 3; i++) {
    const dot = document.getElementById('step' + i + '-dot');
    const label = document.getElementById('step' + i + '-label');
    
    dot.classList.remove('active', 'completed');
    label.classList.remove('active');
    
    if (i < currentStep) {
      dot.classList.add('completed');
    } else if (i === currentStep) {
      dot.classList.add('active');
      label.classList.add('active');
    }
  }

  // Update buttons
  const backBtn = document.getElementById('backBtn');
  const nextBtn = document.getElementById('nextBtn');
  const submitBtn = document.getElementById('submitBtn');

  if (currentStep === 1) {
    backBtn.style.display = 'none';
    nextBtn.style.display = 'inline-block';
    submitBtn.style.display = 'none';
  } else if (currentStep === 2) {
    backBtn.style.display = 'inline-block';
    nextBtn.style.display = 'inline-block';
    submitBtn.style.display = 'none';
  } else if (currentStep === 3) {
    backBtn.style.display = 'inline-block';
    nextBtn.style.display = 'none';
    submitBtn.style.display = 'inline-block';
  }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  updateStepIndicator();

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') closePreparednessAnalysis();
  });

  const dashboardMain = document.getElementById('dashboard-main');
  document.getElementById('inventoryScrollTop').addEventListener('click', function () {
    dashboardMain.scrollTo({ top: 0, behavior: 'smooth' });
  });
  document.getElementById('inventoryScrollBottom').addEventListener('click', function () {
    dashboardMain.scrollTo({ top: dashboardMain.scrollHeight, behavior: 'smooth' });
  });
  // Up / down move one screen at a time (a little less, so the last lines stay in view).
  document.getElementById('inventoryScrollUp').addEventListener('click', function () {
    dashboardMain.scrollBy({ top: -dashboardMain.clientHeight * 0.8, behavior: 'smooth' });
  });
  document.getElementById('inventoryScrollDown').addEventListener('click', function () {
    dashboardMain.scrollBy({ top: dashboardMain.clientHeight * 0.8, behavior: 'smooth' });
  });
  // Grey out the buttons that cannot move any further.
  function syncScrollButtons() {
    const atTop = dashboardMain.scrollTop <= 2;
    const atBottom = dashboardMain.scrollTop + dashboardMain.clientHeight >= dashboardMain.scrollHeight - 2;
    ['inventoryScrollTop', 'inventoryScrollUp'].forEach(function (id) { document.getElementById(id).disabled = atTop; });
    ['inventoryScrollDown', 'inventoryScrollBottom'].forEach(function (id) { document.getElementById(id).disabled = atBottom; });
  }
  dashboardMain.addEventListener('scroll', syncScrollButtons, { passive: true });
  window.addEventListener('resize', syncScrollButtons);
  document.querySelectorAll('.dash-tab, .inventory-category-btn').forEach(function (b) { b.addEventListener('click', function () { setTimeout(syncScrollButtons, 350); }); });
  syncScrollButtons();

  const savedScrollPosition = sessionStorage.getItem('inventoryScrollTop');
  if (savedScrollPosition !== null) {
    const scrollTop = parseInt(savedScrollPosition, 10) || 0;
    sessionStorage.removeItem('inventoryScrollTop');
    window.requestAnimationFrame(function() {
      if (dashboardMain) dashboardMain.scrollTop = scrollTop;
      window.scrollTo(0, scrollTop);
    });
  }

  document.querySelectorAll('.inventory-save').forEach(function(form) {
    form.addEventListener('submit', function() {
      const dashboardMain = document.getElementById('dashboard-main');
      sessionStorage.setItem('inventoryScrollTop', String(dashboardMain ? dashboardMain.scrollTop : window.scrollY));
    });
  });
});
</script>
<?php if (!empty($low_count) && $low_count > 0): ?>
<script>
// Show a browser notification if permission is granted (asks permission if needed)
if ("Notification" in window) {
  (function(){
    function sendNotif(){
      try { new Notification('Low stock alert', { body: '<?php echo addslashes($low_count . " item(s) low. Open dashboard to view details."); ?>' }); } catch(e){}
    }
    if (Notification.permission === 'granted') { sendNotif(); }
    else if (Notification.permission !== 'denied') {
      Notification.requestPermission().then(function(p){ if (p === 'granted') sendNotif(); });
    }
  })();
}
</script>
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// Dashboard Overview charts. All numbers come from this barangay's equipment reports (passed in below).
(function () {
  var D = <?php echo json_encode($ov_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
  if (!D.items.length || !window.Chart || !document.getElementById('ovPrepChart')) return;
  var C = { navy: '#1e5631', blue: '#f2a93b', teal: '#3f9b46', red: '#c0392b', light: '#f7c873', green: '#3f9b46', orange: '#ea7a00' };
  var CAT_COLORS = { 'Tools & Equipment': C.orange, 'Medical Supplies': C.teal };
  var LEVELS = ['Sufficient', 'Low', 'Out of stock'], LEVEL_COLORS = [C.teal, C.blue, C.red];
  Chart.defaults.font.family = "'Segoe UI', Roboto, Arial, sans-serif";
  Chart.defaults.color = '#344054';
  var charts = {}, sortKey = 'qty', sortAsc = true;
  function fmt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 1 }); }
  function make(id, cfg) { if (charts[id]) charts[id].destroy(); charts[id] = new Chart(document.getElementById(id), cfg); }
  // Labels with value and percent on doughnut slices.
  var sliceLabels = { id: 'sliceLabels', afterDatasetsDraw: function (c) {
    var ds = c.data.datasets[0], total = ds.data.reduce(function (a, b) { return a + b; }, 0), g = c.ctx;
    c.getDatasetMeta(0).data.forEach(function (arc, i) {
      if (!ds.data[i] || ds.data[i] / total < 0.06) return;
      var p = arc.tooltipPosition(); g.save(); g.fillStyle = '#fff'; g.font = '700 12px Segoe UI, Arial'; g.textAlign = 'center';
      g.fillText(Math.round(ds.data[i] / total * 100) + '%', p.x, p.y + 4); g.restore();
    });
  } };
  var doughnutOpts = { responsive: true, maintainAspectRatio: false, cutout: '45%', plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 12 } },
    tooltip: { callbacks: { label: function (i) { var t = i.dataset.data.reduce(function (a, b) { return a + b; }, 0); return i.label + ': ' + fmt(i.parsed) + ' (' + (t ? Math.round(i.parsed / t * 1000) / 10 : 0) + '%)'; } } } } };

  function render() {
    var cat = document.getElementById('ovCat').value;
    var items = D.items.filter(function (it) { return !cat || it.cat === cat; });
    var qty = items.reduce(function (s, it) { return s + it.qty; }, 0);
    document.getElementById('kItems').textContent = items.length;
    document.getElementById('kQty').textContent = fmt(qty);
    document.getElementById('kOut').textContent = items.filter(function (it) { return it.level === 'Out of stock'; }).length;
    document.getElementById('kLow').textContent = items.filter(function (it) { return it.level === 'Low'; }).length;
  }
  // ---- Disaster Preparedness Analysis: items each disaster needs, scored from the latest report ----
  var HAZARDS = {
    flood: ['Flood', ['life_jacket','rope','carabiner','harness','flashlight','umbrella','first_aid_kit','stretcher','gloves','facemask','elastic_bandage','gauze_pad','betadine_dropper']],
    typhoon: ['Typhoon', ['life_jacket','rope','flashlight','umbrella','helmet','first_aid_kit','portable_oxygen','gloves','facemask','surgical_tape','gauze_pad']],
    earthquake: ['Earthquake', ['helmet','impact_resistant_boots','stretcher_scoop','backboard','spider_strap','cervical_collar','s_splint','w_splint','t_bandage','flashlight','first_aid_kit','gauze_pad','elastic_bandage']],
    fire: ['Fire', ['hazmat_suit','helmet','portable_oxygen','oxygen_mask','nasal_cannula','resuscitator','defibrillator','facemask','gloves','cold_hot_compress']],
    medical: ['Medical emergency', ['bp_automatic','pulse_oximeter','portable_oxygen','resuscitator','defibrillator','aspirator','oxygen_mask','nasal_cannula','gloves','facemask','gauze_pad','betadine_dropper','surgical_tape']]
  };
  var TARGET = <?php echo FORECAST_REORDER_LEVEL + 1; ?>;
  var byKey = {}; D.items.forEach(function (it) { byKey[it.key] = it; });
  function hazardRows(h) {
    return HAZARDS[h][1].map(function (k) {
      var it = byKey[k], target = TARGET;
      return { name: it ? it.name : k.replace(/_count$/, '').replace(/_/g, ' '), qty: it ? it.qty : null, pct: it ? Math.min(100, Math.round(it.qty / target * 100)) : 0, target: target };
    });
  }
  function score(h) { var r = hazardRows(h); return Math.round(r.reduce(function (s, x) { return s + x.pct; }, 0) / r.length); }
  function pctColor(p) { return p >= 100 ? C.teal : (p >= 50 ? C.blue : C.red); }
  function renderPrep() {
    var h = document.getElementById('ovHazard').value, rows = hazardRows(h).sort(function (a, b) { return a.pct - b.pct; }), sc = score(h);
    document.getElementById('ovPrepLabel').textContent = HAZARDS[h][0] + ' preparedness';
    document.getElementById('ovPrepScore').textContent = sc + '%';
    var m = document.getElementById('ovPrepMeter'); m.style.width = sc + '%'; m.style.background = sc >= 80 ? '#8fe388' : (sc >= 50 ? '#f2a93b' : '#ff7b6e');
    var weak = rows.filter(function (r) { return r.pct < 100; }).map(function (r) { return r.name; });
    document.getElementById('ovPrepNote').textContent = sc >= 80 ? 'Well prepared for a ' + HAZARDS[h][0].toLowerCase() + '. Keep up routine checks and restocking.'
      : (sc >= 50 ? 'Partly prepared. Restock: ' + weak.slice(0, 5).join(', ') + (weak.length > 5 ? ' and ' + (weak.length - 5) + ' more.' : '.')
      : 'Not yet prepared. Restock first: ' + weak.slice(0, 5).join(', ') + (weak.length > 5 ? ' and ' + (weak.length - 5) + ' more.' : '.'));
    make('ovPrepChart', { type: 'bar', data: { labels: rows.map(function (r) { return r.name; }), datasets: [{ label: 'Readiness', data: rows.map(function (r) { return r.pct; }), backgroundColor: rows.map(function (r) { return pctColor(r.pct); }), borderRadius: 3 }] },
      options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (i) { var r = rows[i.dataIndex]; return r.qty === null ? 'Not reported' : 'Have ' + r.qty + ' of ' + r.target + ' needed (' + r.pct + '%)'; } } } },
        scales: { x: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } } } });
    var keys = Object.keys(HAZARDS), scores = keys.map(score);
    make('ovHazardChart', { type: 'bar', data: { labels: keys.map(function (k) { return HAZARDS[k][0]; }), datasets: [{ label: 'Preparedness', data: scores, backgroundColor: keys.map(function (k, i) { return k === h ? C.orange : '#b9d7b4'; }), borderRadius: 4 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (i) { return i.parsed.y + '% prepared'; } } } },
        scales: { y: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } }, x: { grid: { display: false } } },
        onClick: function (e, el) { if (el.length) { document.getElementById('ovHazard').value = keys[el[0].index]; renderPrep(); } } } });
  }
  document.getElementById('ovHazard').addEventListener('change', renderPrep);
  document.getElementById('ovCat').addEventListener('change', render);
  // Charts are drawn when the Overview tab is visible (a hidden canvas has no size).
  var panel = document.getElementById('dashPanelOverview'), drawn = false;
  function maybe() { if (!panel.hidden && !drawn) { drawn = true; render(); renderPrep(); } }
  new MutationObserver(maybe).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  maybe();
})();
</script>
<?php echo exp_fields_assets(); ?>
<style>
  .exp-help { margin:0 0 10px; color:#667085; font-size:13px; }
  .exp-removed { margin-top:12px; padding:8px 12px; border:1px dashed #fda29b; border-radius:10px; background:#fffbfa; }
  .exp-removed summary { cursor:pointer; color:#b42318; font-weight:700; font-size:13px; }
  .exp-banner { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin:0 0 12px; padding:10px 14px; border:1px solid #fedf89; border-left:5px solid #f79009; border-radius:10px; background:#fffaeb; color:#7a2e0e; font-size:13.5px; }
  .exp-banner.x { border-color:#fda29b; border-left-color:#d92d20; background:#fef3f2; color:#7a271a; }
  .exp-banner-ico { font-size:20px; } .exp-banner > span:nth-child(2) { flex:1; min-width:220px; }
  .exp-banner-btn { margin:0; padding:7px 12px; border:0; border-radius:8px; background:#b54708; color:#fff; font:inherit; font-size:12.5px; font-weight:800; cursor:pointer; width:auto; }
  .exp-banner.x .exp-banner-btn { background:#d92d20; }
  .exp-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:0 0 12px; }
  .exp-card { padding:10px 12px; border-radius:10px; border:1px solid #eaecf0; background:#fff; }
  .exp-card b { display:block; font-size:22px; } .exp-card span { font-size:12px; color:#667085; }
  .exp-card.expired b { color:#b42318; } .exp-card.soon b { color:#b54708; } .exp-card.unset b { color:#475467; } .exp-card.ok b { color:#067647; }
  .exp-pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11.5px; font-weight:800; white-space:nowrap; }
  .exp-pill.expired { background:#fee4e2; color:#b42318; } .exp-pill.soon { background:#fef0c7; color:#b54708; } .exp-pill.unset { background:#f2f4f7; color:#475467; } .exp-pill.ok { background:#d1fadf; color:#067647; }
  .exp-table tr.exp-row-expired td { background:#fffbfa; }
  .exp-table .exp-act { display:flex !important; flex-direction:row !important; flex-wrap:wrap; gap:6px; align-items:center; margin:4px 0 0; }
  .exp-table .exp-act input { width:auto !important; min-width:0; max-width:150px; height:auto !important; min-height:0 !important; flex:0 1 140px; margin:0 !important; padding:6px 8px !important; border:1px solid #d0d5dd; border-radius:6px; font:inherit; font-size:12.5px; line-height:1.3; }
  .exp-table td:last-child { min-width:250px; }
  .exp-act .btn { margin:0; }
  .exp-auto { color:#b42318; font-weight:700; }
  .exp-table tr.exp-row-gone td { color:#7a271a; }
  .exp-edit summary { cursor:pointer; color:#c2410c; font-weight:700; font-size:12.5px; }
  .exp-first { display:block; margin-top:3px; color:#b54708; font-weight:700; }
  .exp-line { font-size:12px; color:#475467; text-decoration:none; } .exp-line:hover { text-decoration:underline; }
  .exp-line .x { color:#b42318; } .exp-line .s { color:#b54708; }
  .exp-btn { border-color:#f5c48a; }
  @media (max-width:760px) { .exp-cards { grid-template-columns:1fr 1fr; } }
</style>
<div class="txm" id="txModal" hidden>
  <form method="post" action="item_transactions.php" class="txm-box" id="txmForm">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="record_tx" value="1">
    <input type="hidden" name="return" value="dashboard">
    <input type="hidden" name="txn_type" id="txmType">
    <input type="hidden" name="item_key" id="txmItem">
    <input type="hidden" name="inventory_category" id="txmCat">
    <div class="txm-head"><b id="txmTitle"></b><button type="button" class="txm-x" aria-label="Close">&times;</button></div>
    <div class="txm-body">
      <p class="txm-stock" id="txmStock"></p>
      <label>Quantity<input type="number" name="quantity" id="txmQty" min="1" step="1" required></label>
      <label><span id="txmWhoLabel">Name of the person who took out the item</span><input name="counterpart" id="txmWho" maxlength="150" required placeholder="e.g. Juan Dela Cruz"></label>
      <label>Remarks / Purpose<input name="remarks" maxlength="500" required placeholder="e.g. Used during flood response at Block 5"></label>
      <?php echo exp_fields_html(); ?>
      <div class="txm-auto"><span>Date &amp; time: <b id="txmNow"></b></span><span>Recorded by: <b><?php echo e($_SESSION['user_name'] ?? ''); ?></b></span></div>
      <button type="submit" class="btn" id="txmSave" style="width:100%;margin:6px 0 0;">Save</button>
      <p class="txm-note">Saved to the Audit Logs. The quantity in Main Inventory updates right away.</p>
    </div>
  </form>
</div>
<style>
  .inv-tx { display:flex; gap:6px; flex-wrap:wrap; }
  .inv-tx button { margin:0; padding:6px 12px; border-radius:8px; font:inherit; font-size:12px; font-weight:800; cursor:pointer; width:auto; }
  .inv-tx .out { background:#fff; color:#b42318; border:1px solid #fecdca; } .inv-tx .out:hover { background:#fef3f2; }
  .inv-tx .in { background:#fff; color:#067647; border:1px solid #abefc6; } .inv-tx .in:hover { background:#ecfdf3; }
  .inv-tx .out:disabled { opacity:.45; cursor:not-allowed; }
  .txm { position:fixed; inset:0; z-index:2100; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.5); }
  .txm[hidden] { display:none; }
  .txm-box { width:min(460px,100%); margin:0; display:block !important; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.3); }
  .txm-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; font-size:16px; }
  .txm-x { width:auto; margin:0; border:0; background:transparent; color:#fff; font-size:26px; line-height:1; cursor:pointer; }
  .txm-body { padding:16px 18px; display:grid; gap:10px; }
  .txm-body label { display:grid; gap:5px; margin:0; font-size:12px; font-weight:700; color:#344054; text-transform:none; letter-spacing:0; }
  .txm-body input { margin:0; width:100%; box-sizing:border-box; padding:10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:14px; }
  .txm-stock { margin:0; font-size:14px; color:#475467; } .txm-stock b { color:#1f2937; }
  .txm-auto { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; font-size:12px; color:#667085; background:#f9fafb; border-radius:8px; padding:8px 10px; }
  .txm-note { margin:0; font-size:11.5px; color:#98a2b3; text-align:center; }
</style>
<script>
(function () {
  // Release / Obtain from Main Inventory: one small form, saved to the Audit Logs (and the stock updates).
  var m = document.getElementById('txModal'); if (!m) return;
  var qty = document.getElementById('txmQty'), who = document.getElementById('txmWhoLabel'), stock = document.getElementById('txmStock'), have = 0, rel = true;
  function now() { document.getElementById('txmNow').textContent = new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }); }
  setInterval(function () { if (!m.hidden) now(); }, 1000);
  function show() { var n = parseInt(qty.value, 10) || 0, after = rel ? have - n : have + n; stock.innerHTML = 'On hand: <b>' + have + '</b>' + (n ? ' &rarr; after this: <b style="color:' + (after < 0 ? '#b42318' : '#1f2937') + '">' + after + '</b>' + (after < 0 ? ' (not enough stock)' : '') : ''); }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.inv-tx button'); if (!b) return;
    rel = b.dataset.type === 'released'; have = +b.dataset.qty;
    document.getElementById('txmType').value = b.dataset.type;
    document.getElementById('txmItem').value = b.dataset.item;
    document.getElementById('txmCat').value = b.dataset.cat;
    document.getElementById('txmTitle').textContent = (rel ? '\u2191 Item Out: ' : '\u2193 Item In: ') + b.dataset.name;
    document.getElementById('txmSave').textContent = rel ? 'Save Item Out' : 'Save Item In';
    who.textContent = rel ? 'Name of the person who took out the item' : 'Name of the person who returned / brought in the item';
    qty.value = ''; qty.max = rel ? have : 99999;
    m.querySelectorAll('input[name=counterpart], input[name=remarks]').forEach(function (i) { i.value = ''; });
    if (window.expFields) expFields(document.getElementById('txmForm'), b.dataset.type, b.dataset.item);
    show(); now(); m.hidden = false; qty.focus();
  });
  qty.addEventListener('input', show);
  m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('.txm-x')) m.hidden = true; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') m.hidden = true; });
  var f = document.getElementById('txmForm');
  f.addEventListener('submit', function (e) { if (f.dataset.sent) { e.preventDefault(); return; } f.dataset.sent = '1'; });
})();
</script>
</body>
</html>
