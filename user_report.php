<?php
require_once 'init.php';
require_login();
if (is_role_in(['head_admin','official','admin'])) { header('Location: admin_dashboard.php'); exit; }

$user_id = $_SESSION['user_id'];
$msg = '';
$error = '';
$contact_chat_messages = [];
$profile_logo_stmt = $conn->prepare('SELECT profile_logo FROM users WHERE id = ? LIMIT 1');
$profile_logo = '';
if ($profile_logo_stmt) {
  $profile_logo_stmt->bind_param('i', $user_id);
  $profile_logo_stmt->execute();
  $profile_logo = $profile_logo_stmt->get_result()->fetch_assoc()['profile_logo'] ?? '';
  $profile_logo_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact_message'])) {
  $contact_message = trim($_POST['message'] ?? '');
  if ($contact_message === '') {
    $error = 'Please enter a message before sending.';
  } else {
    $contact_stmt = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'user', ?)");
    if ($contact_stmt) {
      $contact_stmt->bind_param('iis', $user_id, $user_id, $contact_message);
      if ($contact_stmt->execute()) $msg = 'Message sent to the admin.';
      else $error = 'Unable to send your message.';
      $contact_stmt->close();
    }
  }
}

// Fetch existing report for this user
$existing = $conn->prepare('SELECT * FROM equipment_reports WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1');
$existing->bind_param('i', $user_id);
$existing->execute();
$report = $existing->get_result()->fetch_assoc();

$report_month = $_GET['report_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $report_month)) $report_month = date('Y-m');
$report_history = [];
$report_history_stmt = $conn->prepare('SELECT * FROM equipment_reports WHERE user_id = ? AND submitted_at >= ? AND submitted_at < ? ORDER BY submitted_at DESC');
if ($report_history_stmt) {
  $month_start = $report_month . '-01 00:00:00';
  $next_month = (new DateTime($month_start))->modify('+1 month')->format('Y-m-d H:i:s');
  $report_history_stmt->bind_param('iss', $user_id, $month_start, $next_month);
  $report_history_stmt->execute();
  $report_history_result = $report_history_stmt->get_result();
  while ($history_row = $report_history_result->fetch_assoc()) $report_history[] = $history_row;
  $report_history_stmt->close();
}

$chat_stmt = $conn->prepare("SELECT sender_role, IF(deleted_at IS NULL, message, 'This message was removed') AS message, created_at FROM contact_messages WHERE user_id = ? ORDER BY created_at ASC, id ASC");
if ($chat_stmt) {
  $chat_stmt->bind_param('i', $user_id);
  $chat_stmt->execute();
  $chat_result = $chat_stmt->get_result();
  while ($chat_row = $chat_result->fetch_assoc()) $contact_chat_messages[] = $chat_row;
  $chat_stmt->close();
}

$inventory_field_map = [
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
$report_history_items = [
  ['portable_oxygen', 'Portable Oxygen'], ['stretcher_scoop', 'Stretcher / Scoop'],
  ['spider_strap', 'Spider Strap'], ['bp_automatic', 'BP Automatic / Manual'],
  ['pulse_oximeter', 'Pulse Oximeter'], ['cervical_collar', 'Cervical Collar'],
  ['first_aid_kit', 'First Aid Kit'], ['stretcher', 'Stretcher'], ['backboard', 'Backboard'],
  ['resuscitator', 'Resuscitator'], ['defibrillator', 'Defibrillator (AED)'],
  ['aspirator', 'Aspirator / Suction Device'], ['flashlight', 'Flashlight / Search Light'],
  ['rope', 'Rope / Cord'], ['carabiner', 'Carabiner / Hooks'], ['harness', 'Safety Harness'],
  ['life_jacket', 'Life Jacket'], ['helmet', 'Safety Helmet'],
  ['impact_resistant_boots', 'Impact Resistant Boots'], ['hazmat_suit', 'HAZMAT Suit / PPE'],
  ['t_bandage', 'T-Bandage'], ['s_splint', 'S Splint'], ['w_splint', 'W Splint'],
  ['umbrella', 'Umbrella'], ['cold_hot_compress', 'Cold / Hot Compress'],
  ['facemask', 'Facemask'], ['elastic_bandage', 'Elastic Bandage'], ['gloves', 'Gloves'],
  ['betadine_dropper', 'Betadine Dropper'], ['surgical_tape', 'Surgical Tape'],
  ['gauze_pad', '4x4 Gauze Pad'], ['nasal_cannula', 'Nasal Cannula'], ['oxygen_mask', 'Oxygen Mask']
];
$show_report_history = ($_GET['report_history'] ?? '') === '1';

function get_inventory_values($conn, $field_map) {
  $values = [];
  foreach ($field_map as $field => [$pattern, $section]) {
    $stmt = $conn->prepare('SELECT current_stock FROM inventory WHERE item_description LIKE ? AND section = ? ORDER BY id DESC LIMIT 1');
    if ($stmt) {
      $like = '%' . $pattern . '%';
      $stmt->bind_param('ss', $like, $section);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      if ($row) $values[$field] = (int)$row['current_stock'];
      $stmt->close();
    }
  }
  return $values;
}

function render_report_items($items, $inventory_values, $report) {
  foreach ($items as [$key, $label]) {
    $qty = $inventory_values[$key] ?? (isset($report[$key]) ? $report[$key] : 0);
    $rem = $report[$key.'_remarks'] ?? '';
    echo '<div style="display:grid;grid-template-columns:1fr 80px 80px 80px 400px;gap:8px;margin-bottom:8px;padding:8px;background:#f5f5f5;border-radius:4px;">';
    echo '<label>'.$label.'</label>';
    echo '<input type="text" name="'.$key.'" placeholder="Qty" value="'.e($qty).'" style="font-size:11px;">';
    echo '<span style="text-align:center;font-size:11px;font-weight:600;">IN</span>';
    echo '<span style="text-align:center;font-size:11px;font-weight:600;">OUT</span>';
    echo '<input type="text" name="'.$key.'_remarks" placeholder="Remarks" value="'.e($rem).'" style="font-size:11px;">';
    echo '</div>';
  }
}

$inventory_values = get_inventory_values($conn, $inventory_field_map);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ambulance_count = (int)($_POST['ambulance_count'] ?? 0);
  $fire_truck_count = (int)($_POST['fire_truck_count'] ?? 0);

  $fields = ['portable_oxygen', 'stretcher_scoop', 'spider_strap', 'bp_automatic', 'pulse_oximeter', 'facemask',
         'elastic_bandage', 'gloves', 'betadine_dropper', 'surgical_tape', 'gauze_pad', 'nasal_cannula',
         'oxygen_mask', 't_bandage', 's_splint', 'w_splint', 'umbrella', 'cold_hot_compress',
         'cervical_collar', 'first_aid_kit', 'stretcher', 'backboard', 'resuscitator', 'defibrillator',
         'aspirator', 'flashlight', 'rope', 'carabiner', 'harness', 'life_jacket', 'helmet',
         'impact_resistant_boots', 'hazmat_suit'];

  // Prepare parameters in order and types string
  $params = [];
  $types = '';

  // Keep every submission as a separate historical report.
  $cols = ['user_id', 'ambulance_count', 'fire_truck_count'];
  $placeholders = ['?', '?', '?'];
  $params[] = $user_id; $types .= 'i';
  $params[] = $ambulance_count; $types .= 'i';
  $params[] = $fire_truck_count; $types .= 'i';

  foreach ($fields as $f) {
    $qty = isset($_POST[$f]) && $_POST[$f] !== '' ? (int)$_POST[$f] : 0;
    $rem = $_POST[$f.'_remarks'] ?? '';
    $cols[] = $f;
    $cols[] = $f . '_remarks';
    $placeholders[] = '?';
    $placeholders[] = '?';
    $params[] = $qty; $types .= 'i';
    $params[] = $rem; $types .= 's';
  }

  // "4. Others": items not on the checklist. Blank rows are ignored; a quantity or remark needs a name.
  $posted_others = [];
  $other_names = (array)($_POST['other_name'] ?? []);
  foreach ($other_names as $i => $other_name) {
    $other_name = trim((string)$other_name);
    $other_qty_raw = trim((string)(($_POST['other_qty'] ?? [])[$i] ?? ''));
    $other_rem = trim((string)(($_POST['other_remarks'] ?? [])[$i] ?? ''));
    if ($other_name === '' && $other_qty_raw === '' && $other_rem === '') continue;
    $posted_others[] = ['name' => $other_name, 'qty' => $other_qty_raw, 'remarks' => $other_rem];
    if ($other_name === '') { $error = 'Give each item under "4. Others" a name (or clear that row).'; continue; }
    if (mb_strlen($other_name) > 100 || mb_strlen($other_rem) > 255) { $error = 'An item name under "4. Others" is too long (100 characters max, remarks 255).'; continue; }
    if ($other_qty_raw !== '' && (!ctype_digit($other_qty_raw) || (int)$other_qty_raw > 99999)) { $error = 'Quantities under "4. Others" must be whole numbers 0 or more (check "' . $other_name . '").'; continue; }
  }
  if (count($posted_others) > 50) $error = 'Up to 50 items can be listed under "4. Others".';
  $other_items_json = json_encode(array_map(function ($o) {
    return ['name' => $o['name'], 'qty' => (int)$o['qty'], 'remarks' => $o['remarks']];
  }, $posted_others), JSON_UNESCAPED_UNICODE);
  $cols[] = 'other_items'; $placeholders[] = '?';
  $params[] = $other_items_json; $types .= 's';

  $sql = 'INSERT INTO equipment_reports (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
  $stmt = $error ? null : $conn->prepare($sql);

  if ($error) {
    // Validation message already set; nothing is saved so the barangay can fix the "Others" rows.
  } elseif (!$stmt) {
    $error = 'Prepare failed: ' . $conn->error;
  } else {
    // bind_param requires references
    $bind_names = [];
    $bind_names[] = & $types;
    for ($i = 0; $i < count($params); $i++) {
      $bind_names[] = & $params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);

    if ($stmt->execute()) {
      $msg = 'Report submitted successfully!';
      audit_log('EQUIPMENT_REPORT_SUBMITTED', 'user #' . $user_id);
      try { require_once __DIR__ . '/expiry_lib.php'; exp_sync_user($user_id, 'report'); } catch (Throwable $e) {} // expiry tracking follows the new quantities
      restock_engine_trigger(true); // a new report may make this barangay critical: notify right away
      foreach ($inventory_field_map as $field => [$pattern, $section]) {
        if (!isset($_POST[$field])) continue;
        $quantity = max(0, (int)$_POST[$field]);
        $like = '%' . $pattern . '%';
        if ($section === 'Medical Supplies') {
          $sync = $conn->prepare("UPDATE inventory SET current_stock = ?, status = CASE WHEN ? <= 15 THEN 'Low Stock' ELSE 'Verified' END WHERE item_description LIKE ? AND section = ? LIMIT 1");
          if ($sync) {
            $sync->bind_param('iiss', $quantity, $quantity, $like, $section);
            $sync->execute();
            $sync->close();
          }
        } else {
          $sync = $conn->prepare('UPDATE inventory SET current_stock = ? WHERE item_description LIKE ? AND section = ? LIMIT 1');
          if ($sync) {
            $sync->bind_param('iss', $quantity, $like, $section);
            $sync->execute();
            $sync->close();
          }
        }
      }
      $existing->execute();
      $report = $existing->get_result()->fetch_assoc();
      $inventory_values = get_inventory_values($conn, $inventory_field_map);
      $report_month = date('Y-m');
      $report_history = [$report];
    } else {
      $error = 'Error saving report: ' . $stmt->error;
    }
  }
}

// Rows for "4. Others": what was just typed if saving failed, otherwise the latest report's list (so it carries over).
$form_others = ($error && isset($posted_others)) ? $posted_others : report_other_items($report ?: []);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Equipment Report</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .report-form { max-width:1000px; }
    .equipment-row { display:grid; grid-template-columns: 1fr 60px 60px 60px 200px; gap:8px; margin-bottom:8px; align-items:center; }
    .equipment-row label { font-weight:600; font-size:12px; }
    .equipment-row input { padding:6px; border:1px solid #ccc; border-radius:4px; }
    .report-category-nav { display:flex; gap:10px; flex-wrap:wrap; margin:16px 0 20px; }
    .report-category-btn { border:1px solid var(--primary); background:#fff; color:var(--primary); padding:10px 14px; border-radius:4px; font-weight:600; cursor:pointer; }
    .report-category-btn.active, .report-category-btn:hover { background:var(--primary); color:#fff; }
    .report-section { display:none; }
    .report-section.active { display:block; }
    .report-steps-nav { display:flex; align-items:center; gap:12px; margin-top:20px; flex-wrap:wrap; }
    .report-steps-nav .btn { width:auto; margin:0; padding:12px 22px; font-size:14px; }
    .report-steps-nav [hidden] { display:none !important; }
    .report-step-label { flex:1; text-align:center; color:#667085; font-size:13px; font-weight:600; }
    .report-step-back { background:#fff !important; color:#c56a00 !important; border:1px solid #f5c48a !important; }
    .report-step-next, .report-step-submit { margin-left:auto !important; }
    .report-step-submit { padding:12px 28px !important; }
    .report-history-top { display:inline-flex; align-items:center; gap:6px; padding:11px 20px; border:0; border-radius:10px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; font:inherit; font-size:14px; font-weight:700; letter-spacing:.02em; cursor:pointer; box-shadow:0 6px 16px rgba(234,122,0,.3); }
    .report-history-top:hover, .report-history-top:focus-visible { filter:brightness(1.08); outline:none; transform:translateY(-1px); }
    .report-top-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .report-top-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 16px; border-radius:10px; font:inherit; font-size:14px; font-weight:700; cursor:pointer; }
    .report-top-btn.secondary { border:1px solid #f5c48a; background:#fff; color:#9a4a00; margin:0; text-transform:none; letter-spacing:0; }
    .report-top-btn.secondary:hover, .report-top-btn.secondary:focus-visible { background:#fff7ed; border-color:#ff8c00; outline:none; }
    /* Result of a spreadsheet import, shown above the checklist */
    .import-result { display:none; margin:0 0 16px; padding:12px 14px; border-radius:10px; font-size:13px; line-height:1.5; }
    .import-result.show { display:block; }
    .import-result.ok { background:#ecfdf3; border:1px solid #abefc6; color:#05603a; }
    .import-result.warn { background:#fffaeb; border:1px solid #fedf89; color:#93370d; }
    .import-result.err { background:#fef3f2; border:1px solid #fecdca; color:#b42318; }
    .import-result ul { margin:6px 0 0 18px; padding:0; }
    .import-result-close { float:right; border:0; background:transparent; color:inherit; font-size:18px; line-height:1; cursor:pointer; }
    /* 4. Others */
    .others-count { display:inline-block; min-width:20px; margin-left:6px; padding:0 6px; border-radius:999px; background:#ea7a00; color:#fff; font-size:11px; line-height:18px; text-align:center; }
    .others-count[hidden] { display:none; }
    .report-category-btn.active .others-count { background:#fff; color:#9a4a00; }
    .others-help { margin:0 0 12px; color:#667085; font-size:13px; }
    .others-head, .others-row { display:grid; grid-template-columns:minmax(0,1.4fr) 110px minmax(0,1.6fr) 36px; gap:8px; align-items:center; }
    .others-head { padding:0 8px 6px; color:#667085; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .others-row { margin-bottom:8px; padding:8px; background:#f5f5f5; border-radius:6px; }
    .others-row input { margin:0; font-size:13px; }
    .others-remove { width:32px; height:32px; border:1px solid #fecdca; border-radius:8px; background:#fff; color:#b42318; font-size:18px; line-height:1; cursor:pointer; }
    .others-remove:hover, .others-remove:focus-visible { background:#fef3f2; outline:none; }
    .others-add { margin-top:4px; padding:9px 16px; border:1px dashed #ff8c00; border-radius:8px; background:#fff7ed; color:#9a4a00; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .others-add:hover, .others-add:focus-visible { background:#ffedd5; outline:none; }
    .others-empty { margin:4px 0 10px; color:#98a2b3; font-size:13px; }
    @media (max-width:640px) { .others-head { display:none; } .others-row { grid-template-columns:1fr 90px 36px; } .others-row input[name="other_remarks[]"] { grid-column:1 / -1; grid-row:2; } }
    .imported-field { background:#fff7ed !important; border-color:#ff8c00 !important; box-shadow:0 0 0 2px rgba(255,140,0,.15); }
    @media (max-width:640px) { .report-top-actions { width:100%; } .report-top-actions > * { flex:1 1 100%; justify-content:center; } }
    .report-history-modal { display:none; position:fixed; inset:0; z-index:1100; align-items:center; justify-content:center; padding:24px; background:rgba(0,0,0,.45); }
    .report-history-modal.active { display:flex; }
    .report-history-content { width:min(1000px, 100%); max-height:90vh; overflow-y:auto; background:#fff; border-radius:6px; box-shadow:0 8px 30px rgba(0,0,0,.25); }
    .report-history-header { display:flex; align-items:center; justify-content:space-between; gap:16px; background:var(--primary); color:#fff; padding:14px 16px; }
    .report-history-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; }
    .report-history-filter { display:flex; align-items:end; gap:10px; padding:16px; background:#f5f8f6; border-bottom:1px solid #e0e0e0; }
    .report-history-filter label { display:flex; flex-direction:column; gap:5px; font-weight:600; color:#444; }
    .report-history-filter input { padding:8px; border:1px solid #ccc; border-radius:4px; font:inherit; }
    .report-history-entry { margin:16px; border:1px solid #dfe7e2; border-radius:5px; overflow:hidden; }
    .report-history-entry h4 { margin:0; padding:10px 12px; background:#eef8f2; color:var(--primary); }
    .report-history-table { width:100%; border-collapse:collapse; }
    .report-history-table th, .report-history-table td { padding:8px 10px; border-bottom:1px solid #edf0ee; text-align:left; font-size:13px; }
    .report-history-table th { color:#666; width:34%; }
    .report-history-toolbar .btn, .report-history-actions .btn { background:#fff; color:var(--primary); border:1px solid var(--primary); }
    .report-history-toolbar .btn:hover, .report-history-actions .btn:hover { background:#eef8f2; color:var(--primary-dark); }
    .report-history-actions { display:flex; justify-content:flex-end; padding:0 16px 4px; }
    @media (max-width:760px) { .report-history-filter { align-items:stretch; flex-direction:column; } }
    @media print {
      body * { visibility:hidden !important; }
      #reportHistoryModal.active, #reportHistoryModal.active * { visibility:visible !important; }
      #reportHistoryModal.active { display:block; position:absolute; inset:0; padding:0; background:#fff; }
      #reportHistoryModal .report-history-content { width:100%; max-height:none; overflow:visible; box-shadow:none; border-radius:0; }
      #reportHistoryModal .report-history-filter, #reportHistoryModal .report-history-actions, #reportHistoryModal .report-history-close { display:none; }
      #reportHistoryModal .report-history-header { color:#000; background:#fff; border-bottom:2px solid #2d7a5c; }
      #reportHistoryModal .report-history-entry { break-inside:avoid; }
    }
    .contact-admin-float { position:fixed; left:24px; bottom:24px; width:48px; height:48px; display:flex; align-items:center; justify-content:center; background:var(--primary); color:#fff; border-radius:50%; font-size:22px; box-shadow:0 3px 10px rgba(0,0,0,.2); z-index:1001; }
    .contact-admin-float:hover { background:var(--primary-dark); color:#fff; text-decoration:none; }
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
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;font-size:24px;">Submit Inventory</h2>
        <p style="margin:4px 0 0 0;color:#666;font-size:13px;">Caloocan City Disaster Risk Reduction Management Department</p>
      </div>
      <div class="report-top-actions">
        <button type="button" class="report-top-btn secondary" id="xlsxTemplateBtn" title="Download a spreadsheet already laid out for this checklist">⬇️ Download Template</button>
        <label class="report-top-btn secondary" for="xlsxImportInput" title="Fill the checklist from an Excel (.xlsx) or CSV file">📥 Import Spreadsheet</label>
        <input type="file" id="xlsxImportInput" accept=".xlsx,.xls,.csv" hidden>
        <button type="button" class="report-history-top" onclick="openReportHistory()">🗂️ Report History</button>
      </div>
    </header>

    <main class="main-content" id="reportMain" style="flex:1;overflow-y:auto;">
      <div class="container">
        <section class="card report-form">
          <?php if ($msg) echo '<div class="notice">'.$msg.'</div>'; ?>
          <?php if ($error) echo '<div class="errors">'.$error.'</div>'; ?>
          <form method="post" id="reportForm">
            <h3 style="background:#2d7a5c;color:#fff;padding:12px;margin:-20px -20px 12px -20px;">Equipment &amp; Supplies Checklist</h3>
            <div class="report-category-nav" aria-label="Report categories">
              <button type="button" class="report-category-btn active" onclick="selectReportSection('report-tools', this)">1. Tools & Equipment</button>
              <button type="button" class="report-category-btn" onclick="selectReportSection('report-supplies', this)">2. Medical Supplies</button>
              <button type="button" class="report-category-btn" id="othersTabBtn" onclick="selectReportSection('report-others', this)">3. Others<span class="others-count" id="othersCount"<?php echo $form_others ? '' : ' hidden'; ?>><?php echo count($form_others); ?></span></button>
            </div>
            <div class="import-result" id="importResult" role="status" aria-live="polite"></div>
            
            <!-- Tools and equipment -->
            <div class="report-section active" id="report-tools">
            <h4 style="color:#2d7a5c;border-bottom:2px solid #2d7a5c;padding-bottom:8px;">Tools & Equipment</h4>
            
            <?php
              $tools_equipment = [
                ['portable_oxygen', 'Portable Oxygen (Always Check Before Use)'],
                ['stretcher_scoop', 'Stretcher / Scoop'],
                ['spider_strap', 'Spider Strap'],
                ['bp_automatic', 'BP Automatic / Manual'],
                ['pulse_oximeter', 'Pulse Oximeter'],
                ['cervical_collar', 'Cervical Collar'],
                ['first_aid_kit', 'First Aid Kit'],
                ['stretcher', 'Stretcher'],
                ['backboard', 'Backboard'],
                ['resuscitator', 'Resuscitator'],
                ['defibrillator', 'Defibrillator (AED)'],
                ['aspirator', 'Aspirator/Suction Device'],
                ['flashlight', 'Flashlight / Search Light'],
                ['rope', 'Rope / Cord'],
                ['carabiner', 'Carabiner / Hooks'],
                ['harness', 'Safety Harness'],
                ['life_jacket', 'Life Jacket'],
                ['helmet', 'Safety Helmet'],
                ['impact_resistant_boots', 'Impact Resistant Boots'],
                ['hazmat_suit', 'HAZMAT Suit / PPE'],
                ['t_bandage', 'T - Bandage'],
                ['s_splint', 'S Splint'],
                ['w_splint', 'W- Splint'],
                ['umbrella', 'Umbrella'],
                ['cold_hot_compress', 'Cold / Hot Compress'],
              ];
              $medical_supplies = [
                ['facemask', 'Facemask'],
                ['elastic_bandage', 'Elastic Bandage (2inch / 3inch)'],
                ['gloves', 'Gloves'],
                ['betadine_dropper', 'Betadine Dropper'],
                ['surgical_tape', 'Surgical Tape / Plastic Tape'],
                ['gauze_pad', '4x4 Gauze Pad'],
                ['nasal_cannula', 'Nasal Cannula'],
                ['oxygen_mask', 'Oxygen Mask'],
              ];
              render_report_items($tools_equipment, $inventory_values, $report);
            ?>

            </div>

            <!-- Medical supplies -->
            <div class="report-section" id="report-supplies">
            <h4 style="color:#2d7a5c;border-bottom:2px solid #2d7a5c;padding-bottom:8px;margin-top:20px;">Medical Supplies</h4>
            <p style="margin:0 0 10px;padding:8px 10px;border-radius:8px;background:#fffaeb;color:#7a2e0e;font-size:12.5px;">🗓 Count only <b>usable</b> items. Do not include expired supplies: they are removed from your stock automatically on their expiry date (see View Inventory &gt; Expiry Tracking).</p>
            <?php
              render_report_items($medical_supplies, $inventory_values, $report);
            ?>
            </div>

            <!-- Others: items the barangay has that are not on the checklist -->
            <div class="report-section" id="report-others">
              <h4 style="color:#2d7a5c;border-bottom:2px solid #2d7a5c;padding-bottom:8px;margin-top:20px;">Others</h4>
              <p class="others-help">Add any tool, equipment or supply your barangay has that is not in sections 1–3 (for example: generator set, chainsaw, sandbags). The DRRM Office sees these with the rest of your report.</p>
              <div class="others-head" aria-hidden="true"><span>Item name</span><span>Quantity</span><span>Remarks</span><span></span></div>
              <div id="othersRows">
                <?php foreach ($form_others as $other): ?>
                  <div class="others-row">
                    <input type="text" name="other_name[]" maxlength="100" placeholder="Item name" aria-label="Item name" value="<?php echo e($other['name']); ?>">
                    <input type="number" name="other_qty[]" min="0" max="99999" step="1" placeholder="0" aria-label="Quantity" value="<?php echo e((string)$other['qty']); ?>">
                    <input type="text" name="other_remarks[]" maxlength="255" placeholder="Remarks" aria-label="Remarks" value="<?php echo e($other['remarks']); ?>">
                    <button type="button" class="others-remove" aria-label="Remove this item" title="Remove">&times;</button>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="others-empty" id="othersEmpty"<?php echo $form_others ? ' hidden' : ''; ?>>No other items yet.</p>
              <button type="button" class="others-add" id="othersAdd">＋ Add item</button>
              <template id="othersRowTemplate">
                <div class="others-row">
                  <input type="text" name="other_name[]" maxlength="100" placeholder="Item name" aria-label="Item name">
                  <input type="number" name="other_qty[]" min="0" max="99999" step="1" placeholder="0" aria-label="Quantity">
                  <input type="text" name="other_remarks[]" maxlength="255" placeholder="Remarks" aria-label="Remarks">
                  <button type="button" class="others-remove" aria-label="Remove this item" title="Remove">&times;</button>
                </div>
              </template>
            </div>

            <div class="report-steps-nav">
              <button type="button" class="btn report-step-back" id="reportBack" hidden>&larr; Back</button>
              <span class="report-step-label" id="reportStepLabel">Step 1 of 3</span>
              <button type="button" class="btn report-step-next" id="reportNext">Next: Tools &amp; Equipment &rarr;</button>
              <button type="submit" class="btn report-step-submit" id="reportSubmit" hidden>📤 Submit Inventory to Admin</button>
            </div>
          </form>
        </section>
      </div>
    </main>
  </div>
</div>
<div class="report-history-modal<?php echo $show_report_history ? ' active' : ''; ?>" id="reportHistoryModal" onclick="closeReportHistory(event)" aria-hidden="<?php echo $show_report_history ? 'false' : 'true'; ?>">
  <div class="report-history-content" onclick="event.stopPropagation()">
    <div class="report-history-header">
      <strong>🗂️ My Equipment Report History</strong>
      <button type="button" class="report-history-close" onclick="closeReportHistory()" aria-label="Close report history">&times;</button>
    </div>
    <form method="get" class="report-history-filter">
      <input type="hidden" name="report_history" value="1">
      <label for="reportMonth">Select report month
        <input type="month" id="reportMonth" name="report_month" value="<?php echo e($report_month); ?>" onchange="this.form.submit()">
      </label>
    </form>
    <div class="report-history-actions">
      <?php if ($report_history): ?><style>.print-pick{display:inline-flex !important;align-items:center;gap:8px;margin:0 10px 0 0;font-size:13px;font-weight:700;color:#344054;text-transform:none !important;letter-spacing:0 !important;}.print-pick select{width:auto;margin:0;padding:8px 10px;border:1px solid #f5c48a;border-radius:8px;font:inherit;font-size:13px;background:#fff;}</style><label class="print-pick">Print <select class="print-sections" title="Choose what part of the report to print"><option value="">Whole report</option><option value="equipment">Tools &amp; equipment only</option><option value="supplies">Medical supplies only</option><option value="equipment,supplies">Tools &amp; equipment + medical supplies</option><option value="others">Other items only</option></select></label><a class="btn" href="report_print.php?month=<?php echo e($report_month); ?>&amp;autoprint=1" style="text-decoration:none;display:inline-block;">🖨️ Print all reports this month</a><?php endif; ?>
    </div>
    <?php if (!$report_history): ?>
      <p style="padding:16px;color:#666;margin:0;">No equipment reports were submitted during <?php echo e(date('F Y', strtotime($report_month . '-01'))); ?>.</p>
    <?php else: ?>
      <?php foreach ($report_history as $history_report): ?>
        <div class="report-history-entry">
          <h4 style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span>Submitted <?php echo e(date('F j, Y g:i A', strtotime($history_report['submitted_at']))); ?></span><a href="report_print.php?id=<?php echo (int)$history_report['id']; ?>&amp;autoprint=1" style="font-size:13px;font-weight:700;color:#c56a00;text-decoration:none;white-space:nowrap;">🖨️ Print this report</a></h4>
          <table class="report-history-table">
            <tbody>
              <?php foreach ($report_history_items as [$history_key, $history_label]): ?>
                <?php $history_value = trim((string)($history_report[$history_key] ?? '')); ?>
                <?php $history_remarks = trim((string)($history_report[$history_key . '_remarks'] ?? '')); ?>
                <?php if ($history_value !== '' || $history_remarks !== ''): ?>
                  <tr>
                    <th><?php echo e($history_label); ?></th>
                    <td><?php echo e($history_value === '' ? '0' : $history_value); ?><?php echo $history_remarks !== '' ? ' - ' . e($history_remarks) : ''; ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php foreach (report_other_items($history_report) as $history_other): ?>
                <tr>
                  <th><?php echo e($history_other['name']); ?> <small style="color:#c56a00;font-weight:700;">(Other)</small></th>
                  <td><?php echo (int)$history_other['qty']; ?><?php echo $history_other['remarks'] !== '' ? ' - ' . e($history_other['remarks']) : ''; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<div class="contact-chat-modal" id="contactChatModal" onclick="closeContactChat(event)">
  <div class="contact-chat" onclick="event.stopPropagation()">
    <div class="contact-chat-header">
      <strong>💬 Contact Admin</strong>
      <button type="button" class="contact-chat-close" onclick="closeContactChat()" aria-label="Close chat">&times;</button>
    </div>
    <div class="contact-chat-messages">
      <?php if (empty($contact_chat_messages)): ?>
        <p style="color:#666;font-size:13px;margin:0;">Send a message to communicate with the admin.</p>
      <?php else: ?>
        <?php foreach ($contact_chat_messages as $chat_message): ?>
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
// Checklist steps: Next / Back move through the sections; Submit appears only on the last one (3. Others).
const REPORT_STEPS = [['report-tools', 'Tools & Equipment'], ['report-supplies', 'Medical Supplies'], ['report-others', 'Others']];
function selectReportSection(sectionId, button) {
  document.querySelectorAll('.report-section').forEach(function(section) {
    section.classList.toggle('active', section.id === sectionId);
  });
  const buttons = document.querySelectorAll('.report-category-btn');
  const i = REPORT_STEPS.findIndex(function (s) { return s[0] === sectionId; });
  if (!button) button = buttons[i];
  buttons.forEach(function(categoryButton) {
    categoryButton.classList.toggle('active', categoryButton === button);
  });
  const last = i === REPORT_STEPS.length - 1;
  document.getElementById('reportBack').hidden = i <= 0;
  document.getElementById('reportNext').hidden = last;
  document.getElementById('reportSubmit').hidden = !last;
  document.getElementById('reportStepLabel').textContent = 'Step ' + (i + 1) + ' of ' + REPORT_STEPS.length;
  if (!last) document.getElementById('reportNext').innerHTML = 'Next: ' + REPORT_STEPS[i + 1][1].replace(/&/g, '&amp;') + ' &rarr;';
}
function reportStep(delta) {
  const current = document.querySelector('.report-section.active');
  const i = REPORT_STEPS.findIndex(function (s) { return current && s[0] === current.id; });
  const next = REPORT_STEPS[Math.max(0, Math.min(REPORT_STEPS.length - 1, i + delta))];
  selectReportSection(next[0]);
  document.getElementById('reportForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('reportNext').addEventListener('click', function () { reportStep(1); });
  document.getElementById('reportBack').addEventListener('click', function () { reportStep(-1); });
  // Enter in a field goes to the next section instead of sending the report early.
  document.getElementById('reportForm').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.type === 'submit' || e.target.type === 'button') return;
    if (!document.getElementById('reportSubmit').hidden) return;
    e.preventDefault(); reportStep(1);
  });
});

function openContactChat(event) {
  if (event) event.preventDefault();
  document.getElementById('contactChatModal').classList.add('active');
}

function closeContactChat(event) {
  if (event && event.target !== event.currentTarget) return;
  document.getElementById('contactChatModal').classList.remove('active');
}

function openReportHistory() {
  const modal = document.getElementById('reportHistoryModal');
  modal.classList.add('active');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

function closeReportHistory(event) {
  if (event && event.target !== event.currentTarget) return;
  const modal = document.getElementById('reportHistoryModal');
  modal.classList.remove('active');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

function printReportHistory() {
  window.print();
}

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') closeReportHistory();
});

// ---- 4. Others: add / remove rows for items that are not on the checklist ----
const othersUi = (function () {
  const rowsBox = document.getElementById('othersRows');
  const tpl = document.getElementById('othersRowTemplate');
  const empty = document.getElementById('othersEmpty');
  const count = document.getElementById('othersCount');
  if (!rowsBox || !tpl) return null;
  function refresh() {
    const n = Array.prototype.filter.call(rowsBox.querySelectorAll('input[name="other_name[]"]'), function (i) { return i.value.trim() !== ''; }).length;
    empty.hidden = rowsBox.children.length > 0;
    count.hidden = n === 0;
    count.textContent = n;
  }
  function addRow(name, qty, remarks, focus) {
    const row = tpl.content.firstElementChild.cloneNode(true);
    const inputs = row.querySelectorAll('input');
    if (name != null) inputs[0].value = name;
    if (qty != null) inputs[1].value = qty;
    if (remarks != null) inputs[2].value = remarks;
    rowsBox.appendChild(row);
    refresh();
    if (focus) inputs[0].focus();
    return row;
  }
  document.getElementById('othersAdd').addEventListener('click', function () { addRow(null, null, null, true); });
  rowsBox.addEventListener('click', function (e) {
    const btn = e.target.closest('.others-remove');
    if (!btn) return;
    btn.closest('.others-row').remove();
    refresh();
  });
  rowsBox.addEventListener('input', refresh);
  <?php if ($error && strpos($error, 'Others') !== false): ?>
  // The save failed because of an "Others" row: open that section so it can be fixed.
  selectReportSection('report-others', document.getElementById('othersTabBtn'));
  <?php endif; ?>
  refresh();
  return {
    addRow: addRow,
    rows: function () {
      return Array.prototype.map.call(rowsBox.querySelectorAll('.others-row'), function (r) {
        const i = r.querySelectorAll('input');
        return { name: i[0].value.trim(), qty: i[1].value, remarks: i[2].value.trim(), el: r };
      });
    }
  };
})();

// ---- Spreadsheet import / template (runs in the browser with SheetJS; nothing is uploaded until "Submit") ----
(function () {
  const form = document.getElementById('reportForm');
  const resultBox = document.getElementById('importResult');
  const fileInput = document.getElementById('xlsxImportInput');
  const templateBtn = document.getElementById('xlsxTemplateBtn');
  if (!form || !resultBox || !fileInput || !templateBtn) return;

  const SECTION_NAMES = { 'report-tools': 'Tools & Equipment', 'report-supplies': 'Medical Supplies' };

  // Every checklist item read straight from the form, so the template and the import always match the page.
  function checklistFields() {
    const fields = [];
    form.querySelectorAll('input[type="number"][name], input[type="text"][name]').forEach(function (input) {
      if (/_remarks$/.test(input.name) || input.name.indexOf('[') !== -1) return;
      const row = input.parentElement;
      const labelEl = row.querySelector('label');
      const section = input.closest('.report-section');
      fields.push({
        key: input.name,
        label: (labelEl ? labelEl.textContent : input.name).replace(/:\s*$/, '').trim(),
        section: section ? (SECTION_NAMES[section.id] || '') : '',
        qty: input,
        remarks: form.querySelector('input[name="' + input.name + '_remarks"]')
      });
    });
    return fields;
  }

  // "Elastic Bandage (2inch / 3inch)" -> "elastic bandage 2inch 3inch"
  function norm(s) { return String(s == null ? '' : s).toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').trim(); }
  const STOP = { the: 1, and: 1, or: 1, of: 1, always: 1, check: 1, before: 1, use: 1, device: 1, count: 1, ppe: 1 };
  function words(s) { return norm(s).split(' ').filter(function (w) { return w && !STOP[w]; }); }

  // Match a spreadsheet row to a checklist item: exact key, exact name, then best word overlap.
  function findField(fields, keyCell, nameCell) {
    const k = norm(keyCell).replace(/ /g, '_');
    if (k) { const byKey = fields.find(function (f) { return f.key === k; }); if (byKey) return byKey; }
    const n = norm(nameCell || keyCell);
    if (!n) return null;
    const exact = fields.find(function (f) { return norm(f.label) === n || norm(f.key.replace(/_/g, ' ')) === n; });
    if (exact) return exact;
    // Ignore spacing: "Face mask" = "Facemask", "Spiderstrap" = "Spider Strap".
    const compact = n.replace(/ /g, '');
    const joined = fields.find(function (f) { const l = norm(f.label).replace(/ /g, ''); return l === compact || f.key.replace(/_/g, '') === compact || (compact.length > 4 && l.indexOf(compact) === 0); });
    if (joined) return joined;
    const target = words(n);
    let best = null, bestScore = 0;
    fields.forEach(function (f) {
      const cand = words(f.label + ' ' + f.key.replace(/_/g, ' '));
      const hits = target.filter(function (w) { return cand.some(function (c) { return c === w || (w.length > 3 && (c.indexOf(w) === 0 || w.indexOf(c) === 0)); }); }).length;
      const score = hits / Math.max(target.length, 1);
      if (score > bestScore) { bestScore = score; best = f; }
    });
    return bestScore >= 0.6 ? best : null;
  }

  function headerIndex(header, patterns) {
    for (let i = 0; i < header.length; i++) {
      const h = norm(header[i]);
      if (patterns.some(function (p) { return p.test(h); })) return i;
    }
    return -1;
  }

  function show(kind, html) {
    resultBox.className = 'import-result show ' + kind;
    resultBox.innerHTML = '<button type="button" class="import-result-close" aria-label="Dismiss">&times;</button>' + html;
    resultBox.querySelector('.import-result-close').addEventListener('click', function () { resultBox.className = 'import-result'; });
    resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function importRows(rows) {
    const fields = checklistFields();
    // Find the header row (the first row that mentions a quantity column) - allows a title row above the table.
    let h = rows.findIndex(function (r) { return headerIndex(r, [/^(qty|quantity|count|stock|on hand|current)/, /quantity/]) !== -1; });
    if (h === -1) {
      show('err', '<strong>Could not read this file.</strong> The sheet needs a <em>Quantity</em> column. Use <strong>Download Template</strong> to get a ready-made file.');
      return;
    }
    const header = rows[h];
    const iQty = headerIndex(header, [/^(qty|quantity|count|stock|on hand|current)/, /quantity/]);
    const iKey = headerIndex(header, [/^key$/, /^code$/, /^field/]);
    const iName = headerIndex(header, [/^(item|equipment|name|description|supply|supplies)/]);
    const iRem = headerIndex(header, [/^remark/, /^note/, /^comment/]);

    document.querySelectorAll('.imported-field').forEach(function (el) { el.classList.remove('imported-field'); });
    let filled = 0;
    const unmatched = [], invalid = [], seen = {}, addedOthers = [];
    rows.slice(h + 1).forEach(function (r, idx) {
      const keyCell = iKey !== -1 ? r[iKey] : '';
      const nameCell = iName !== -1 ? r[iName] : '';
      const qtyCell = r[iQty];
      if (norm(keyCell) === '' && norm(nameCell) === '') return; // blank line
      const field = findField(fields, keyCell, nameCell);
      const label = String(nameCell || keyCell).trim();
      if (!field) {
        // Not on the checklist: keep it under "4. Others" (quantity must still be a whole number).
        const oRaw = String(qtyCell == null ? '' : qtyCell).replace(/,/g, '').trim();
        const oQty = oRaw === '' ? 0 : Number(oRaw);
        if (!othersUi || !label) { unmatched.push(label); return; }
        if (!isFinite(oQty) || oQty < 0 || Math.floor(oQty) !== oQty) { invalid.push(label + ' (“' + oRaw + '”)'); return; }
        const oRem = iRem !== -1 && r[iRem] != null ? String(r[iRem]).trim().slice(0, 255) : '';
        const existing = othersUi.rows().find(function (o) { return norm(o.name) === norm(label); });
        const row = existing ? existing.el : othersUi.addRow(label.slice(0, 100), '', '', false);
        const ins = row.querySelectorAll('input');
        ins[1].value = String(oQty);
        if (oRem) ins[2].value = oRem;
        ins.forEach(function (x) { if (x.value !== '') x.classList.add('imported-field'); });
        addedOthers.push(label);
        return;
      }
      const raw = String(qtyCell == null ? '' : qtyCell).replace(/,/g, '').trim();
      if (raw !== '') {
        const n = Number(raw);
        if (!isFinite(n) || n < 0 || Math.floor(n) !== n) { invalid.push(label + ' (“' + raw + '”)'); return; }
        field.qty.value = String(n);
        field.qty.classList.add('imported-field');
      }
      if (iRem !== -1 && field.remarks && r[iRem] != null && String(r[iRem]).trim() !== '') {
        field.remarks.value = String(r[iRem]).trim().slice(0, 255);
        field.remarks.classList.add('imported-field');
      }
      if (!seen[field.key]) { seen[field.key] = 1; filled++; }
    });

    const missing = fields.filter(function (f) { return !seen[f.key]; }).map(function (f) { return f.label; });
    if (addedOthers.length) document.getElementById('othersRows').dispatchEvent(new Event('input', { bubbles: true }));
    let html = '<strong>' + (filled || addedOthers.length ? '✅ Filled ' + filled + ' of ' + fields.length + ' items from the spreadsheet.' : 'No checklist items were found in this file.') + '</strong> ';
    html += filled ? 'Imported values are highlighted in orange. Check them in sections 1–3, then press <strong>Submit Inventory to Admin</strong>.' : 'Use <strong>Download Template</strong> for the expected layout.';
    if (invalid.length) html += '<br><strong>Skipped (quantity must be a whole number 0 or more):</strong><ul>' + invalid.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
    if (addedOthers.length) html += '<br><strong>Added to “4. Others” (not on the checklist):</strong> ' + esc(addedOthers.slice(0, 8).join(', ')) + (addedOthers.length > 8 ? ' and ' + (addedOthers.length - 8) + ' more' : '') + '.';
    if (unmatched.length) html += '<strong>Not on the checklist (ignored):</strong><ul>' + unmatched.slice(0, 10).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + (unmatched.length > 10 ? '<li>…and ' + (unmatched.length - 10) + ' more</li>' : '') + '</ul>';
    if (filled && missing.length) html += '<strong>Not in the file (left unchanged):</strong> ' + esc(missing.slice(0, 8).join(', ')) + (missing.length > 8 ? ' and ' + (missing.length - 8) + ' more' : '') + '.';
    show(!filled && !addedOthers.length ? 'err' : (invalid.length || unmatched.length ? 'warn' : 'ok'), html);
  }

  fileInput.addEventListener('change', function () {
    const file = fileInput.files && fileInput.files[0];
    fileInput.value = ''; // lets the same file be chosen again later
    if (!file) return;
    if (typeof XLSX === 'undefined') { show('err', '<strong>The spreadsheet reader did not load.</strong> Check the internet connection and refresh the page.'); return; }
    if (file.size > 5 * 1024 * 1024) { show('err', '<strong>This file is larger than 5 MB.</strong> Use the template or remove extra sheets.'); return; }
    const reader = new FileReader();
    reader.onload = function (e) {
      try {
        const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
        // Prefer a sheet called "Checklist", otherwise the first one.
        const name = wb.SheetNames.find(function (n) { return /checklist|inventory|report/i.test(n); }) || wb.SheetNames[0];
        const rows = XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, defval: '', raw: false });
        importRows(rows);
      } catch (err) {
        show('err', '<strong>This file could not be opened.</strong> Save it as .xlsx or .csv and try again.');
      }
    };
    reader.readAsArrayBuffer(file);
  });

  // Template: one row per checklist item, pre-filled with the quantities currently on the form.
  templateBtn.addEventListener('click', function () {
    if (typeof XLSX === 'undefined') { show('err', '<strong>The spreadsheet tool did not load.</strong> Check the internet connection and refresh the page.'); return; }
    const fields = checklistFields();
    const aoa = [['Section', 'Key', 'Item', 'Quantity', 'Remarks']];
    fields.forEach(function (f) {
      const q = Number(f.qty.value);
      aoa.push([f.section, f.key, f.label, isFinite(q) && f.qty.value !== '' ? q : 0, f.remarks ? f.remarks.value : '']);
    });
    if (othersUi) othersUi.rows().forEach(function (o) { if (o.name) aoa.push(['Others', 'other', o.name, Number(o.qty) || 0, o.remarks]); });
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{ wch: 20 }, { wch: 24 }, { wch: 40 }, { wch: 11 }, { wch: 40 }];
    ws['!autofilter'] = { ref: 'A1:E' + aoa.length };
    const help = XLSX.utils.aoa_to_sheet([
      ['How to use this template'],
      [''],
      ['1. Type the current count of each item in the Quantity column (whole numbers, 0 or more).'],
      ['2. Remarks are optional (for example: "2 for repair", "expires Dec 2026").'],
      ['3. Do not change the Key column. Item names may be edited; rows are matched by Key first, then by name.'],
      ['4. Save the file, then on Submit Inventory click "Import Spreadsheet" and choose it.'],
      ['5. Review the highlighted values, then press "Submit Inventory to Admin".'],
      [''],
      ['Rows you delete are left unchanged on the form. Rows for items that are not on the checklist are added under "4. Others".'],
      ['To add another item, add a row with Section "Others", Key "other", the item name, quantity and remarks.']
    ]);
    help['!cols'] = [{ wch: 100 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Checklist');
    XLSX.utils.book_append_sheet(wb, help, 'Instructions');
    const today = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, 'equipment-report-template-' + today + '.xlsx');
  });
})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
// Print buttons: load the formatted report in a hidden frame and open the print dialog right here (no new tab).
document.addEventListener('click', function (e) {
  var a = e.target.closest('a[href*="report_print.php"]');
  if (!a) return;
  e.preventDefault();
  var old = document.getElementById('reportPrintFrame'); if (old) old.remove();
  var f = document.createElement('iframe');
  f.id = 'reportPrintFrame';
  f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  var pick = (a.closest('.report-modal-body, .report-history-content') || document).querySelector('.print-sections');
  f.src = a.getAttribute('href').replace(/&(amp;)?autoprint=1/, '') + '&inframe=1' + (pick && pick.value ? '&sections=' + encodeURIComponent(pick.value) : '');
  f.onload = function () { setTimeout(function () { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (err) { window.open(a.href, '_blank'); } }, 300); };
  document.body.appendChild(f);
}, true); // capture: the Report History box stops normal clicks from bubbling up
</script>
</body>
</html>
