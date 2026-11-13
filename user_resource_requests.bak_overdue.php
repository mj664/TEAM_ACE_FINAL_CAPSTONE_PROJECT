<?php
// Barangay staff: request tools, equipment and supplies from the DRRM Office.
// Requests go into resource_requests, the same table the DRRMC Admin reviews on admin_resource_requests.php;
// the admin's approve/decline reply arrives in the staff member's Messages.
require_once 'init.php';
require_login();
if (is_role_in(['head_admin', 'official', 'admin'])) { header('Location: admin_resource_requests.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
$notice = '';
$error = '';

// Same items as the equipment report, so a request can only name things the barangay actually tracks.
require_once __DIR__ . '/resource_request_lib.php';
$catalog = rr_item_catalog();
$request_types = ['Emergency Supplies', 'Medical Supplies', 'Tools & Equipment'];
$max_qty = 9999;

// What the barangay has now: its latest equipment report.
$on_hand = [];
$latest_stmt = $conn->prepare('SELECT * FROM equipment_reports WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1');
if ($latest_stmt) {
  $latest_stmt->bind_param('i', $user_id);
  $latest_stmt->execute();
  $on_hand = $latest_stmt->get_result()->fetch_assoc() ?: [];
  $latest_stmt->close();
}
$has_report = !empty($on_hand);

$form = ['type' => '', 'reason' => '', 'other' => '', 'qty' => [], 'method' => 'delivery'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_resource_request'])) {
  $form['type'] = (string)($_POST['request_type'] ?? '');
  $form['reason'] = trim((string)($_POST['reason'] ?? ''));
  $form['other'] = trim((string)($_POST['other_items'] ?? ''));
  $form['method'] = ($_POST['preferred_method'] ?? '') === 'pickup' ? 'pickup' : 'delivery'; // the DRRMC Admin makes the final choice
  $parts = [];
  foreach ($catalog as $items) {
    foreach ($items as $key => $label) {
      $raw = trim((string)($_POST['qty'][$key] ?? ''));
      if ($raw === '' || $raw === '0') continue;
      if (!ctype_digit($raw) || (int)$raw > $max_qty) { $error = 'Quantities must be whole numbers from 0 to ' . $max_qty . ' (check ' . $label . ').'; break 2; }
      $form['qty'][$key] = (int)$raw;
      $parts[] = (int)$raw . ' ' . $label;
    }
  }
  if (!$error) {
    if (!csrf_valid($_POST['csrf'] ?? '')) {
      $error = 'Your session expired. Please submit the request again.';
    } elseif (!in_array($form['type'], $request_types, true)) {
      $error = 'Choose what kind of request this is.';
    } elseif (!$parts && $form['other'] === '') {
      $error = 'Enter a quantity for at least one item, or describe the items under "Other items".';
    } elseif (mb_strlen($form['reason']) < 5) {
      $error = 'Tell the DRRM Office why the items are needed.';
    } elseif (mb_strlen($form['other']) > 500 || mb_strlen($form['reason']) > 1000) {
      $error = 'The "Other items" or "Reason" text is too long.';
    } else {
      if ($form['other'] !== '') $parts[] = 'Other: ' . $form['other'];
      $items_text = implode(', ', $parts);
      $insert = $conn->prepare('INSERT INTO resource_requests (user_id, request_type, items_requested, reason, preferred_method) VALUES (?, ?, ?, ?, ?)');
      if ($insert) {
        $insert->bind_param('issss', $user_id, $form['type'], $items_text, $form['reason'], $form['method']);
        if ($insert->execute()) {
          audit_log('RESOURCE_REQUEST_SUBMITTED', 'request #' . $insert->insert_id, $form['type'] . ': ' . mb_substr($items_text, 0, 200));
          rr_acknowledge($insert->insert_id); // automatic "request received" message
          $insert->close();
          header('Location: user_resource_requests.php?sent=1'); // post/redirect/get: a refresh never resends
          exit;
        }
        $insert->close();
      }
      $error = 'Unable to send your request. Please try again.';
    }
  }
}
// The barangay can put a delivery reminder in its own Messages (from the Deliveries popup).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remind_delivery'])) {
  $rid = (int)$_POST['remind_delivery'];
  $st = $conn->prepare("SELECT * FROM resource_requests WHERE id = ? AND user_id = ? AND status = 'approved' AND delivery_status IN ('scheduled', 'out') LIMIT 1");
  $st->bind_param('ii', $rid, $user_id); $st->execute(); $dr = $st->get_result()->fetch_assoc(); $st->close();
  if (!csrf_valid($_POST['csrf'] ?? '')) { $error = 'Your session expired. Please try again.'; }
  elseif (!$dr) { $error = 'That delivery is no longer scheduled.'; }
  else {
    rr_message($user_id, rr_is_pickup($dr)
      ? '⏰ Pick-up reminder (' . rr_delivery_countdown($dr['delivery_at']) . '): resource request #' . $rid . ' (' . $dr['request_type'] . ") is ready for pick-up.\n\n"
        . 'When: ' . rr_delivery_line($dr) . "\n\nBring your staff ID (the one you registered with) and the printed request form: " . rr_form_link($rid) . "\n\n- Saved from your Deliveries list"
      : '⏰ Delivery reminder (' . rr_delivery_countdown($dr['delivery_at']) . '): resource request #' . $rid . ' (' . $dr['request_type'] . ')' . ($dr['delivery_status'] === 'out' ? ' is OUT FOR DELIVERY' : ' is scheduled for delivery') . ".\n\n"
        . 'When: ' . rr_delivery_when($dr['delivery_at']) . "\nWhere: " . $dr['delivery_location'] . "\n\nPlease have someone ready to receive the items.\n\n- Saved from your Deliveries list");
    header('Location: user_resource_requests.php?reminded=' . $rid); exit;
  }
}
// The barangay confirms it received a delivery: the items go into its Main Inventory automatically.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_delivery'])) {
  if (!csrf_valid($_POST['csrf'] ?? '')) { $error = 'Your session expired. Please try again.'; }
  else {
    $res = rr_receive((int)$_POST['receive_delivery'], $user_id, $_FILES['proof'] ?? null);
    if ($res['ok']) {
      $_SESSION['rq_flash'] = 'Delivery received. Added to your Main Inventory: ' . ($res['added'] ? implode(', ', array_map(function ($l, $q) { return $q . ' ' . $l; }, array_keys($res['added']), $res['added'])) : 'none') . '.'
        . ($res['manual'] ? ' Add these in your next equipment report: ' . implode(', ', $res['manual']) . '.' : '')
        . ($res['failed'] ? ' Not added: ' . implode(', ', array_keys($res['failed'])) . '.' : '') . ' The DRRM Office was notified, and each item is in your Audit Logs as Item In.';
      header('Location: user_resource_requests.php'); exit;
    }
    $error = $res['error'];
  }
}
// After a missed pick-up the barangay asks for a new date and says why it did not pick up (within the grace period).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resched_pickup'])) {
  $rid = (int)$_POST['resched_pickup'];
  $st = $conn->prepare("SELECT * FROM resource_requests WHERE id = ? AND user_id = ? AND status = 'approved' AND delivery_status = 'missed' LIMIT 1");
  $st->bind_param('ii', $rid, $user_id); $st->execute(); $mr = $st->get_result()->fetch_assoc(); $st->close();
  $new = (string)($_POST['new_date'] ?? ''); $nd = DateTime::createFromFormat('Y-m-d', $new);
  $why = (string)($_POST['miss_reason'] ?? '');
  $why = $why === 'other' ? trim((string)($_POST['miss_reason_other'] ?? '')) : (in_array($why, rr_missed_reasons(), true) ? $why : '');
  $note = mb_substr(trim((string)($_POST['miss_note'] ?? '')), 0, 255);
  if (!csrf_valid($_POST['csrf'] ?? '')) { $error = 'Your session expired. Please try again.'; }
  elseif (!$mr) { $error = 'This pick-up is no longer waiting for a new date.'; }
  elseif (rr_grace_until($mr) < time()) { $error = 'The time to ask for a new pick-up date has ended. Please message the DRRM Office.'; }
  elseif (!$nd || $nd->format('Y-m-d') !== $new || $new <= date('Y-m-d') || $new > date('Y-m-d', strtotime('+30 days'))) { $error = 'Choose a new pick-up date from tomorrow up to 30 days ahead.'; }
  elseif ($why === '' || mb_strlen($why) > 255) { $error = 'Tell the DRRM Office why the items were not picked up.'; }
  else {
    $up = $conn->prepare('UPDATE resource_requests SET resched_date = ?, resched_reason = ?, resched_note = ?, resched_at = NOW() WHERE id = ?');
    $up->bind_param('sssi', $new, $why, $note, $rid); $up->execute(); $up->close();
    rr_history($rid, 'Barangay asked for a new pick-up date: ' . date('M j, Y', strtotime($new)) . ' (reason: ' . $why . ')');
    $text = '📅 New pick-up date requested for resource request #' . $rid . ' (' . $mr['request_type'] . ").\n\n"
      . 'We missed the pick-up on ' . date('F j, Y', strtotime($mr['delivery_at'])) . ".\nReason: " . $why . ($note !== '' ? "\nNote: " . $note : '')
      . "\nNew date asked: " . date('l, F j, Y', strtotime($new)) . "\n\n- " . (string)($_SESSION['user_name'] ?? '');
    $m = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'user', ?)");
    $m->bind_param('iis', $user_id, $user_id, $text); $m->execute(); $m->close();
    audit_log('RESOURCE_PICKUP_NEW_DATE_ASKED', 'request #' . $rid, 'New date ' . $new . '; reason: ' . $why);
    $_SESSION['rq_flash'] = 'New pick-up date sent to the DRRM Office. You will get a message once it is approved.';
    header('Location: user_resource_requests.php'); exit;
  }
}
if (!empty($_SESSION['rq_flash'])) { $notice = $_SESSION['rq_flash']; unset($_SESSION['rq_flash']); }
if (isset($_GET['reminded'])) $notice = 'Reminder saved in your Messages for request #' . (int)$_GET['reminded'] . '.';
if (($_GET['sent'] ?? '') === '1') $notice = 'Request sent to the DRRM Office. You will get their reply in Messages; the status is tracked under My Requests.';

// This user's requests for the "My Requests" storage (requests the DRRM Office removed are not shown).
$my_requests = [];
$mine_stmt = $conn->prepare('SELECT * FROM resource_requests WHERE user_id = ? AND status <> "deleted" ORDER BY requested_at DESC, id DESC');
if ($mine_stmt) {
  $mine_stmt->bind_param('i', $user_id);
  $mine_stmt->execute();
  $result = $mine_stmt->get_result();
  while ($row = $result->fetch_assoc()) $my_requests[] = $row;
  $mine_stmt->close();
}
$pending_mine = count(array_filter($my_requests, function ($r) { return $r['status'] === 'pending'; }));
// Deliveries: upcoming (scheduled / out for delivery), soonest first, then the last few delivered.
$deliveries = array_values(array_filter($my_requests, function ($r) { return $r['status'] === 'approved' && !empty($r['delivery_at']); }));
usort($deliveries, function ($a, $b) {
  $da = !empty($a['received_at']); $db = !empty($b['received_at']);
  if ($da !== $db) return $da ? 1 : -1;
  return $da ? strcmp($b['delivery_at'], $a['delivery_at']) : strcmp($a['delivery_at'], $b['delivery_at']);
});
$upcoming_count = count(array_filter($deliveries, function ($r) { return empty($r['received_at']) && $r['delivery_status'] !== 'cancelled'; }));
$status_labels = ['pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Declined', 'deleted' => 'Archived by DRRM Office'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Resource Requests - DRRMC Caloocan Hub</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .rr-items-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin:10px 0 6px; font-size:13px; }
    .rr-print { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border:1px solid #f5c48a; border-radius:8px; background:#fff7ed; color:#9a4a00; font-size:12px; font-weight:700; text-decoration:none; white-space:nowrap; }
    .rr-print:hover { background:#ffedd5; }
    .rr-items { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:10px; }
    .rr-items th, .rr-items td { padding:5px 8px; border-bottom:1px solid #eaecf0; text-align:left; }
    .rr-items th { font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:#667085; background:#f9fafb; }
    .rr-items th:last-child, .rr-items td:last-child { width:70px; text-align:right; font-variant-numeric:tabular-nums; }
    .rr-items tr.rr-sec td, .rr-items tr.rr-sec td:last-child { width:auto; text-align:left; padding-top:8px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; color:#c2410c; background:#fffaf5; }
    .rr-items tfoot td { font-weight:800; color:#1f2937; border-bottom:0; border-top:2px solid #eaecf0; }
    .rq-page { max-width:1100px; margin:0 auto; }
    .rq-panel { background:rgba(255,255,255,.96); border:1px solid rgba(255,255,255,.7); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); padding:22px; margin-bottom:18px; }
    .layout .rq-panel h3 { margin:0 0 4px; padding:0; background:none; color:#1f2937; font-size:17px; border-radius:0; }
    .rq-sub { margin:0 0 16px; color:#667085; font-size:13px; }
    .rq-top-btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border:0; border-radius:10px; background:var(--theme-strong); color:#fff; font:inherit; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 6px 16px rgba(234,122,0,.3); }
    .rq-top-btn:hover, .rq-top-btn:focus-visible { filter:brightness(1.08); outline:none; }
    .rq-dlv-btn { position:relative; width:46px; height:46px; border:1px solid #f5c48a; border-radius:12px; background:#fff; font-size:22px; line-height:1; cursor:pointer; box-shadow:0 4px 12px rgba(234,122,0,.15); }
    .rq-dlv-btn:hover, .rq-dlv-btn:focus-visible { background:#fff7ed; outline:none; }
    .rq-dlv-btn.has { animation:rqDlvPulse 2.4s ease-in-out infinite; }
    @keyframes rqDlvPulse { 0%,100% { box-shadow:0 4px 12px rgba(234,122,0,.15); } 50% { box-shadow:0 0 0 6px rgba(234,122,0,.18); } }
    @media (prefers-reduced-motion: reduce) { .rq-dlv-btn.has { animation:none; } }
    .rq-dlv-badge { position:absolute; top:-6px; right:-6px; min-width:20px; height:20px; padding:0 5px; border-radius:999px; background:#d92d20; color:#fff; font-size:11px; font-weight:800; line-height:20px; }
    .rq-method { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:0 0 6px; }
    .rq-method label { position:relative; display:block; margin:0; cursor:pointer; }
    .rq-method input { position:absolute; top:12px; left:12px; width:1px; height:1px; margin:0; opacity:0; } /* kept inside its card, so focusing it never scrolls the page */
    .rq-method span { display:block; padding:10px 12px; border:1px solid #f5c48a; border-radius:10px; background:#fff; color:#344054; text-transform:none; letter-spacing:0; }
    .rq-method small { display:block; margin-top:2px; color:#667085; font-size:12px; font-weight:400; }
    .rq-method input:checked + span { border-color:#ea7a00; background:#fff1e0; box-shadow:0 0 0 2px rgba(234,122,0,.18); }
    .rq-method input:focus-visible + span { outline:2px solid #ea7a00; }
    .rq-dlv-why { margin-top:2px; color:#b54708; font-size:12px; font-weight:600; }
    .rq-dlv-bring { margin-top:2px; color:#344054; font-size:12px; } .rq-dlv-bring a { color:#c2410c; font-weight:700; }
    .rq-dlv-intro { margin:0 0 12px; color:#667085; font-size:12.5px; }
    .rq-dlv-item { display:flex; align-items:center; gap:12px; padding:10px 12px; margin-bottom:8px; border:1px solid #eaecf0; border-left:4px solid #6172f3; border-radius:10px; background:#fff; font-size:13px; color:#475467; }
    .rq-dlv-item.out { border-left-color:#f59e0b; background:#fffbeb; } .rq-dlv-item.delivered { border-left-color:#12b76a; opacity:.85; }
    .rq-dlv-date { flex-shrink:0; width:54px; padding:5px 0; border-radius:8px; background:#fff1e0; text-align:center; color:#9a4a00; line-height:1.1; }
    .rq-dlv-date b { display:block; font-size:11px; text-transform:uppercase; } .rq-dlv-date span { display:block; font-size:20px; font-weight:800; } .rq-dlv-date small { font-size:10px; }
    .rq-dlv-info { flex:1; min-width:0; } .rq-dlv-info strong { color:#1f2937; } .rq-dlv-info small { color:#98a2b3; }
    .rq-dlv-info .dlv-chip { margin-left:6px; }
    .rq-dlv-when { margin-top:2px; color:#c2410c; font-weight:700; font-size:12px; }
    .rq-dlv-remind { margin:0; } .rq-dlv-remind button { display:flex; flex-direction:column; align-items:center; gap:2px; padding:6px 10px; border:1px solid #f5c48a; border-radius:10px; background:#fff7ed; color:#9a4a00; font:inherit; font-size:18px; cursor:pointer; }
    .rq-dlv-remind button span { font-size:10.5px; font-weight:700; } .rq-dlv-remind button:hover { background:#ffedd5; }
    .rq-dlv-line { margin:6px 0 2px; padding:6px 10px; border-radius:8px; background:#eef4ff; color:#344054; font-size:12.5px; }
    .dlv-chip { display:inline-block; padding:1px 9px; border-radius:999px; font-size:11px; font-weight:800; }
    .dlv-chip.scheduled { background:#eef4ff; color:#3538cd; } .dlv-chip.out { background:#fef3c7; color:#92400e; } .dlv-chip.delivered { background:#e0f2fe; color:#075985; } .dlv-chip.received { background:#d1fae5; color:#065f46; }
    .rq-dlv-item.received { border-left-color:#12b76a; opacity:.85; } .rq-dlv-item.delivered { border-left-color:#0ea5e9; opacity:1; }
    .rq-got-modal { z-index:1100; }
    .rq-got-for { margin:0 0 8px; font-weight:800; color:#1f2937; }
    .rq-got-steps { margin:0 0 12px; padding-left:20px; color:#475467; font-size:13px; line-height:1.6; }
    .rq-got-pick { display:block; position:relative; padding:18px; border:2px dashed #f5c48a; border-radius:12px; background:#fffaf4; text-align:center; color:#9a4a00; font-weight:700; cursor:pointer; }
    .rq-got-pick:hover { background:#fff1e0; }
    .rq-got-pick input { position:absolute; inset:0; opacity:0; cursor:pointer; }
    .rq-got-pick small { display:block; margin-top:4px; color:#98a2b3; font-weight:400; font-size:11.5px; }
    .rq-got-pick img { display:block; max-width:100%; max-height:260px; margin:0 auto; border-radius:8px; }
    .rq-got-submit { width:100%; margin-top:12px; padding:12px; border:0; border-radius:10px; background:#12b76a; color:#fff; font:inherit; font-size:14px; font-weight:800; cursor:pointer; }
    .rq-got-submit:disabled { opacity:.5; cursor:not-allowed; }
    .dlv-chip.missed { background:#fef3f2; color:#b42318; } .dlv-chip.cancelled { background:#f2f4f7; color:#667085; }
    .rq-dlv-item.missed { border-left-color:#d92d20; background:#fffbfa; } .rq-dlv-item.cancelled { border-left-color:#98a2b3; opacity:.75; }
    .rq-dlv-missed { flex-shrink:0; max-width:150px; text-align:center; font-size:11.5px; color:#7a271a; line-height:1.35; }
    .rq-dlv-missed button { display:flex; flex-direction:column; align-items:center; gap:2px; width:100%; padding:6px 10px; border:1px solid #fda29b; border-radius:10px; background:#fef3f2; color:#b42318; font:inherit; font-size:18px; cursor:pointer; }
    .rq-dlv-missed button span { font-size:10.5px; font-weight:700; } .rq-dlv-missed button:hover { background:#fee4e2; }
    .rq-dlv-missed small { display:block; margin-top:3px; color:#b42318; }
    .rq-miss-form { display:grid; gap:10px; }
    .rq-miss-form label { display:grid; gap:4px; font-size:13px; font-weight:700; color:#344054; }
    .rq-miss-form select, .rq-miss-form input[type=text], .rq-miss-form input[type=date] { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-weight:400; }
    .rq-req-star { color:#b42318; font-weight:600; } .rq-miss-rule { margin:0; color:#667085; font-size:12px; }
    .rq-dlv-btns { display:flex; gap:6px; } .rq-dlv-got button { background:#ecfdf3; border-color:#75e0a7; color:#067647; } .rq-dlv-got button:hover { background:#d1fadf; }
    .rq-top-btn .count { background:rgba(255,255,255,.28); border-radius:999px; padding:1px 8px; font-size:12px; }
    .rq-grid { display:grid; grid-template-columns:260px minmax(0,1fr); gap:14px 18px; align-items:start; }
    .rq-grid label { font-size:13px; font-weight:700; color:#344054; text-transform:none; letter-spacing:0; }
    .rq-grid select, .rq-grid textarea, .rq-grid input[type=text] { width:100%; margin:0; }
    .rq-grid textarea { min-height:80px; resize:vertical; }
    .rq-grid select:focus, .rq-grid textarea:focus, .rq-grid input:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .rq-hint { display:block; margin-top:3px; font-size:11px; font-weight:400; color:#667085; }
    .rq-items-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin:6px 0 10px; }
    .rq-items-search { flex:1 1 240px; max-width:340px; padding:9px 12px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:13px; }
    .rq-tabs { display:flex; gap:6px; flex-wrap:wrap; }
    .rq-tab { padding:8px 12px; border:1px solid #f5c48a; border-radius:8px; background:#fff; color:#9a4a00; font:inherit; font-size:12px; font-weight:700; cursor:pointer; }
    .rq-tab.active, .rq-tab:hover { background:var(--theme-strong); color:#fff; border-color:transparent; }
    .rq-table { width:100%; border-collapse:collapse; font-size:13px; }
    .rq-table th { text-align:left; padding:9px 10px; background:#fff7ed; color:#9a4a00; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
    .rq-table td { padding:8px 10px; border-top:1px solid #f2ebe2; color:#344054; vertical-align:middle; }
    .rq-table tr.rq-section-row td { background:#fafafa; color:#1f2937; font-weight:800; font-size:12px; text-transform:uppercase; letter-spacing:.05em; }
    .rq-table tr.picked td { background:#fff7ed; }
    .rq-have { color:#667085; }
    .rq-have.low { color:#b42318; font-weight:700; }
    .rq-qty { width:90px !important; padding:7px 9px !important; text-align:right; }
    .rq-summary { position:sticky; bottom:0; z-index:5; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin:16px -22px -22px; padding:14px 22px; background:#fff; border-top:1px solid #f0e6da; border-radius:0 0 14px 14px; box-shadow:0 -6px 16px rgba(120,60,0,.08); }
    .rq-summary-text { font-size:13px; color:#475467; }
    .rq-summary-text strong { color:#9a4a00; }
    .rq-submit { padding:12px 22px; border:0; border-radius:10px; background:var(--theme-strong); color:#fff; font:inherit; font-weight:800; font-size:14px; cursor:pointer; }
    .rq-submit:hover { filter:brightness(1.08); }
    .rq-empty-row td { text-align:center; color:#667085; padding:20px; }
    /* My Requests storage */
    .rq-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.55); }
    .rq-modal.open { display:flex; }
    .rq-modal-box { width:min(1060px,100%); max-height:88vh; display:flex; flex-direction:column; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .rq-modal-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; background:var(--theme-strong); color:#fff; }
    .rq-modal-head h3 { margin:0; padding:0; background:none; color:#fff; font-size:17px; }
    .rq-modal-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; border-radius:4px; padding:0 4px; }
    .rq-modal-close:hover, .rq-modal-close:focus-visible { background:rgba(255,255,255,.2); outline:none; }
    .rq-filter { display:flex; gap:6px; flex-wrap:wrap; padding:12px 20px 0; }
    .rq-modal-body { padding:12px 20px 20px; overflow-y:auto; }
    .rq-split { display:grid; grid-template-columns:290px 1fr; gap:18px; min-height:0; flex:1; overflow:hidden; padding:12px 20px 20px; }
    .rq-split .rq-modal-body { padding:0 4px 0 0; }
    .rq-cal { align-self:start; border:1px solid #f2ebe2; border-radius:12px; padding:12px; background:#fffdfa; }
    .rq-cal-head { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:8px; }
    .rq-cal-head strong { font-size:15px; color:#1f2937; }
    .rq-cal-nav { width:30px; height:30px; border:1px solid #f5c48a; border-radius:8px; background:#fff; color:#9a4a00; font-size:15px; font-weight:800; cursor:pointer; }
    .rq-cal-nav:hover { background:#fff7ed; }
    .rq-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
    .rq-cal-wd { text-align:center; font-size:10.5px; font-weight:700; color:#98a2b3; text-transform:uppercase; padding:2px 0; }
    .rq-cal-day { position:relative; aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center; border:1px solid transparent; border-radius:8px; background:transparent; color:#475467; font:inherit; font-size:12.5px; padding:0; cursor:default; }
    .rq-cal-day.has { background:#fff1e0; border-color:#f5c48a; color:#9a4a00; font-weight:800; cursor:pointer; }
    .rq-cal-day.has:hover { background:#ffe2bf; }
    .rq-cal-day.has i { font-style:normal; font-size:9.5px; font-weight:700; line-height:1; margin-top:1px; }
    .rq-cal-day.sel { background:var(--theme-strong); border-color:transparent; color:#fff; }
    .rq-cal-day.today { box-shadow:inset 0 0 0 2px #3f9b46; }
    .rq-cal-key { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; font-size:11.5px; color:#667085; }
    .rq-cal-key span { display:inline-flex; align-items:center; gap:5px; }
    .rq-cal-key b { width:12px; height:12px; border-radius:4px; display:inline-block; }
    .rq-cal-showing { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:10px; padding-top:10px; border-top:1px solid #f2ebe2; font-size:12px; color:#475467; }
    .rq-cal-clear { border:0; background:none; color:#c2410c; font:inherit; font-size:12px; font-weight:700; cursor:pointer; padding:0; }
    .rq-cal-clear[hidden] { display:none; }
    .rq-date-chip { display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; background:#fff1e0; color:#9a4a00; font-size:11px; font-weight:700; }
    .rq-none { color:#667085; padding:16px 0; text-align:center; }
    @media (max-width:760px) { .rq-split { grid-template-columns:1fr; overflow-y:auto; } .rq-split .rq-modal-body { overflow:visible; } }
    .rq-req { padding:12px 0; border-bottom:1px solid #f2ebe2; font-size:13px; color:#475467; line-height:1.5; }
    .rq-req strong { color:#1f2937; }
    .rq-status { float:right; margin-left:8px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; }
    .rq-status.pending { background:#fef3c7; color:#92400e; }
    .rq-status.approved { background:#d1fae5; color:#065f46; }
    .rq-status.rejected { background:#fee2e2; color:#991b1b; }
    .rq-status.deleted { background:#f2f4f7; color:#475467; }
    .rq-req small { color:#98a2b3; }
    @media (max-width:760px) { .rq-grid { grid-template-columns:1fr; } .rq-top-btn { width:100%; justify-content:center; } }
      .rq-xlsx-bar { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:0 0 12px; padding:10px 12px; border:1px dashed #f5c48a; border-radius:12px; background:#fffaf4; }
    .rq-xlsx-label { font-size:13px; font-weight:700; color:#9a4a00; margin-right:2px; }
    .rq-xlsx-btn { display:inline-flex; align-items:center; gap:7px; margin:0; padding:8px 14px; border:1px solid #f5c48a; border-radius:999px; background:#fff; color:#c56a00; font:inherit; font-size:13px; font-weight:700; cursor:pointer; text-transform:none; letter-spacing:0; width:auto; }
    .rq-xlsx-btn:hover, .rq-xlsx-btn:focus-visible { background:#fff1df; outline:none; }
    label.rq-xlsx-btn { color:#c56a00 !important; text-transform:none !important; letter-spacing:0 !important; }
    .rq-import-result { margin:0 0 12px; padding:11px 14px; border-radius:10px; font-size:13px; line-height:1.55; }
    .rq-import-result.ok { background:#ecfdf3; border:1px solid #abefc6; color:#05603a; }
    .rq-import-result.warn { background:#fffaeb; border:1px solid #fedf89; color:#93370d; }
    .rq-import-result.err { background:#fef3f2; border:1px solid #fecdca; color:#b42318; }
    .rq-import-result button { float:right; border:0; background:transparent; color:inherit; font-size:18px; line-height:1; cursor:pointer; }
    .rq-qty.imported, #rqType.imported, #rqReason.imported, #rqOther.imported { background:#fff7ed !important; border-color:#ff8c00 !important; box-shadow:0 0 0 2px rgba(255,140,0,.15); }
</style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;font-size:24px;">Resource Requests</h2>
        <p style="margin:4px 0 0;color:#666;font-size:13px;">Request tools, equipment and medical supplies from the DRRM Office.</p>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" class="rq-dlv-btn<?= $upcoming_count ? ' has' : '' ?>" id="rqDlvOpen" aria-haspopup="dialog" title="Deliveries of your approved requests" aria-label="Deliveries (<?= $upcoming_count ?> to receive)">🚚<?php if ($upcoming_count): ?><span class="rq-dlv-badge"><?= $upcoming_count ?></span><?php endif; ?></button>
        <button type="button" class="rq-top-btn" id="rqStorageOpen" aria-haspopup="dialog">🗂️ My Requests <span class="count"><?= count($my_requests) ?></span></button>
      </div>
    </header>

    <main class="main-content" id="rqMain" style="flex:1;overflow-y:auto;">
      <div class="rq-page">
        <?php if ($notice): ?><div class="notice" style="margin-bottom:14px;"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="errors" style="margin-bottom:14px;"><?= e($error) ?></div><?php endif; ?>

        <section class="rq-panel">
          <h3>📦 New resource request</h3>
          <p class="rq-sub">Enter how many of each item you need. "You have" is taken from your latest equipment report<?= $has_report ? ' (' . e(date('M j, Y', strtotime($on_hand['submitted_at']))) . ')' : '' ?>.<?= $pending_mine ? ' You have ' . $pending_mine . ' request' . ($pending_mine === 1 ? '' : 's') . ' still waiting for review.' : '' ?></p>
          <form method="post" id="rqForm" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="submit_resource_request" value="1">
            <div class="rq-grid">
              <label for="rqType">Request type</label>
              <select name="request_type" id="rqType" required>
                <option value="">Choose a type</option>
                <?php foreach ($request_types as $t): ?><option<?= $form['type'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
              </select>

              <label>Items needed<span class="rq-hint">Leave blank for items you don't need.</span></label>
              <div>
                <div class="rq-xlsx-bar">
                  <span class="rq-xlsx-label">Spreadsheet:</span>
                  <button type="button" class="rq-xlsx-btn" id="rqTemplateBtn" title="A spreadsheet of every item you can request: fill in the Request Qty column, then import it"><i class="fa-solid fa-file-arrow-down"></i> Download Template</button>
                  <label class="rq-xlsx-btn" for="rqImportFile" title="Fill this form from an Excel (.xlsx) or CSV file"><i class="fa-solid fa-file-import"></i> Import Spreadsheet</label>
                  <input type="file" id="rqImportFile" accept=".xlsx,.xls,.csv" hidden>
                </div>
                <div class="rq-import-result" id="rqImportResult" role="status" aria-live="polite" hidden></div>
                <div class="rq-items-head">
                  <div class="rq-tabs" role="group" aria-label="Show items">
                    <button type="button" class="rq-tab active" data-rq-section="">All</button>
                    <?php foreach (array_keys($catalog) as $section): ?><button type="button" class="rq-tab" data-rq-section="<?= e($section) ?>"><?= e($section) ?></button><?php endforeach; ?>
                    <button type="button" class="rq-tab" data-rq-section="__picked">Selected</button>
                  </div>
                  <input type="search" class="rq-items-search" id="rqSearch" placeholder="Search items…" aria-label="Search items">
                </div>
                <table class="rq-table">
                  <thead><tr><th>Item</th><th>You have</th><th style="text-align:right;">Request qty</th></tr></thead>
                  <tbody id="rqItems">
                    <?php foreach ($catalog as $section => $items): ?>
                      <tr class="rq-section-row" data-section="<?= e($section) ?>"><td colspan="3"><?= e($section) ?></td></tr>
                      <?php foreach ($items as $key => $label): ?>
                        <?php $have = $has_report ? (int)($on_hand[$key] ?? 0) : null; $low = $have !== null && $section === 'Medical Supplies' && $have <= 15; ?>
                        <tr class="rq-item<?= isset($form['qty'][$key]) ? ' picked' : '' ?>" data-section="<?= e($section) ?>" data-name="<?= e(strtolower($label)) ?>">
                          <td><label for="qty_<?= e($key) ?>" style="font-weight:600;"><?= e($label) ?></label></td>
                          <td class="rq-have<?= $low ? ' low' : '' ?>"><?= $have === null ? '—' : $have . ($low ? ' · low' : '') ?></td>
                          <td style="text-align:right;"><input type="number" class="rq-qty" id="qty_<?= e($key) ?>" name="qty[<?= e($key) ?>]" min="0" max="<?= $max_qty ?>" step="1" inputmode="numeric" value="<?= isset($form['qty'][$key]) ? (int)$form['qty'][$key] : '' ?>" placeholder="0"></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                    <tr class="rq-empty-row" id="rqNoItems" hidden><td colspan="3">No items match.</td></tr>
                  </tbody>
                </table>
              </div>

              <label for="rqOther">Other items<span class="rq-hint">Optional: anything not on the list.</span></label>
              <input type="text" name="other_items" id="rqOther" maxlength="500" value="<?= e($form['other']) ?>" placeholder="e.g. 20 sandbags, 2 generator sets">

              <label>How do you want to get the items?<span class="rq-hint">The DRRM Office makes the final choice and tells you why if it changes.</span></label>
              <div class="rq-method" role="radiogroup" aria-label="Delivery or pick-up">
                <label><input type="radio" name="preferred_method" value="delivery"<?= $form['method'] !== 'pickup' ? ' checked' : '' ?>><span>🚚 <b>Delivery</b><small>to our barangay</small></span></label>
                <label><input type="radio" name="preferred_method" value="pickup"<?= $form['method'] === 'pickup' ? ' checked' : '' ?>><span>📦 <b>Pick-up</b><small>we claim it at the City DRRM Office</small></span></label>
              </div>

              <label for="rqReason">Reason</label>
              <textarea name="reason" id="rqReason" maxlength="1000" required placeholder="Why the items are needed, e.g. typhoon preparation, stock used during a flood response"><?= e($form['reason']) ?></textarea>
            </div>
            <div class="rq-summary">
              <span class="rq-summary-text" id="rqSummary" aria-live="polite">No items selected yet.</span>
              <button type="submit" class="rq-submit">📤 Send request to DRRM Office</button>
            </div>
          </form>
        </section>
      </div>
    </main>
  </div>
</div>

<div class="rq-modal rq-got-modal" id="rqGot" role="dialog" aria-modal="true" aria-labelledby="rqGotTitle">
  <div class="rq-modal-box" style="width:min(520px,100%);">
    <div class="rq-modal-head">
      <h3 id="rqGotTitle">✅ Confirm delivery received</h3>
      <button type="button" class="rq-modal-close" id="rqGotClose" aria-label="Close">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data" class="rq-modal-body rq-got-form" id="rqGotForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="receive_delivery" id="rqGotId" value="">
      <p class="rq-got-for" id="rqGotFor"></p>
      <ol class="rq-got-steps"><li>Count the delivered items.</li><li>Take a clear photo of the items (with the delivery slip if there is one).</li><li>Confirm: the items are added to your Main Inventory as <b>Item In</b>, and the photo is sent to the DRRM Office.</li></ol>
      <label class="rq-got-pick" id="rqGotPick">
        <input type="file" name="proof" id="rqGotFile" accept="image/jpeg,image/png,image/webp" capture="environment" required>
        <span id="rqGotPickText">📷 Take or choose a proof photo <small>(required &middot; JPG, PNG or WEBP, up to 8 MB)</small></span>
        <img id="rqGotPreview" alt="Proof photo preview" hidden>
      </label>
      <button type="submit" class="rq-got-submit" id="rqGotSubmit" disabled>✅ Confirm received &amp; send photo</button>
    </form>
  </div>
</div>

<div class="rq-modal rq-got-modal" id="rqMiss" role="dialog" aria-modal="true" aria-labelledby="rqMissTitle">
  <div class="rq-modal-box" style="width:min(520px,100%);">
    <div class="rq-modal-head">
      <h3 id="rqMissTitle">📅 Request a new pick-up date</h3>
      <button type="button" class="rq-modal-close" id="rqMissClose" aria-label="Close">&times;</button>
    </div>
    <form method="post" class="rq-modal-body rq-miss-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="resched_pickup" id="rqMissId" value="">
      <p class="rq-got-for" id="rqMissFor"></p>
      <label>Why were the items not picked up? <span class="rq-req-star">(required)</span>
        <select name="miss_reason" id="rqMissReason" required><option value="">Choose a reason</option><?php foreach (rr_missed_reasons() as $mrs): ?><option><?= e($mrs) ?></option><?php endforeach; ?><option value="other">Other (type the reason)</option></select>
      </label>
      <input type="text" name="miss_reason_other" id="rqMissOther" maxlength="255" placeholder="Type the reason" hidden>
      <label>New pick-up date<input type="date" name="new_date" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>" required></label>
      <label>Note <span class="rq-hint">(optional)</span><input type="text" name="miss_note" maxlength="255" placeholder="e.g. We can come in the morning"></label>
      <p class="rq-miss-rule">The DRRM Office reviews the new date and may excuse the miss for valid reasons (e.g. a typhoon). After <?= RR_MAX_MISSES ?> missed pick-ups that are not excused, the request can be cancelled.</p>
      <button type="submit" class="rq-got-submit">📅 Send new date &amp; reason</button>
    </form>
  </div>
</div>

<div class="rq-modal" id="rqDlv" role="dialog" aria-modal="true" aria-labelledby="rqDlvTitle">
  <div class="rq-modal-box" style="width:min(640px,100%);">
    <div class="rq-modal-head">
      <h3 id="rqDlvTitle">🚚 Deliveries &amp; Pick-ups</h3>
      <button type="button" class="rq-modal-close" id="rqDlvClose" aria-label="Close">&times;</button>
    </div>
    <div class="rq-modal-body">
      <p class="rq-dlv-intro">When the DRRM Office approves a request it sets the delivery (or pick-up) date, time and place. For a pick-up, bring your staff ID and the printed request form to the City DRRM Office. You get reminders in Messages the day before and on the delivery day. When the items arrive, count them and tap <b>✅ Received</b> with a proof photo: they are added to your Main Inventory automatically and the photo goes to the DRRM Office.</p>
      <?php if (!$deliveries): ?><p class="rq-none">No deliveries yet. Approved requests will show their delivery schedule here.</p><?php endif; ?>
      <?php foreach ($deliveries as $d): [$bc, $bl] = rr_delivery_badge($d); ?>
        <div class="rq-dlv-item <?= e($bc) ?>">
          <div class="rq-dlv-date"><b><?= e(date('M', strtotime($d['delivery_at']))) ?></b><span><?= e(date('j', strtotime($d['delivery_at']))) ?></span><small><?= e(date('D', strtotime($d['delivery_at']))) ?></small></div>
          <div class="rq-dlv-info">
            <strong><?= e($d['request_type']) ?></strong> <small>#<?= (int)$d['id'] ?></small>
            <span class="dlv-chip <?= e($bc) ?>"><?= e($bl) ?></span>
            <div><?= rr_is_pickup($d) ? '📦 <b>Pick-up</b>' : '🚚 <b>Delivery</b>' ?> &middot; 🕘 <?= e(rr_is_pickup($d) ? rr_pickup_window($d) : date('g:i A', strtotime($d['delivery_at']))) ?> &middot; 📍 <?= e($d['delivery_location']) ?></div>
            <?php if (!empty($d['method_reason'])): ?><div class="rq-dlv-why">Pick-up instead of delivery: <?= e($d['method_reason']) ?></div><?php endif; ?>
            <?php if (rr_is_pickup($d) && $d['delivery_status'] === 'scheduled'): ?><div class="rq-dlv-bring">🪪 Bring your staff ID and the <a href="<?= e(rr_form_link($d['id'])) ?>" target="_blank" rel="noopener">printed request form</a></div><?php endif; ?>
            <?php if (rr_is_pickup($d) && !empty($d['claimed_by_name'])): ?><div class="rq-dlv-bring">🤝 Claimed by <?= e($d['claimed_by_name']) ?><?= !empty($d['claimed_rep']) ? ' (representative)' : '' ?></div><?php endif; ?>
            <div class="rq-dlv-when"><?php if (!empty($d['received_at'])): ?><span style="color:#067647;">Received <?= e(date('M j, Y g:i A', strtotime($d['received_at']))) ?> &middot; <?= (int)$d['received_units'] ?> unit<?= (int)$d['received_units'] === 1 ? '' : 's' ?> added to your inventory</span><?php elseif ($bc === 'delivered'): ?><?= rr_is_pickup($d) ? 'Claimed' : 'Delivered' ?> <?= e(date('M j, Y g:i A', strtotime($d['delivered_at'] ?: $d['delivery_at']))) ?> &middot; tap Received once you have counted the items<?php elseif ($d['delivery_status'] === 'missed'): ?><span style="color:#b42318;">⚠️ Not picked up on <?= e(date('M j, Y', strtotime($d['delivery_at']))) ?></span><?php elseif ($d['delivery_status'] === 'cancelled'): ?><span style="color:#667085;">Cancelled: the items were not picked up</span><?php else: ?><?= e(rr_delivery_countdown($d['delivery_at'])) ?><?php endif; ?></div>
          </div>
          <?php if ($d['delivery_status'] === 'missed'): $gu = rr_grace_until($d); ?>
            <div class="rq-dlv-missed">
              <?php if (!empty($d['resched_date'])): ?>⏳ New date asked: <b><?= e(date('M j', strtotime($d['resched_date']))) ?></b><br>waiting for the DRRM Office
              <?php elseif ($gu >= time()): ?><button type="button" data-miss="<?= (int)$d['id'] ?>" data-miss-title="<?= e($d['request_type'] . ' #' . $d['id'] . ' - missed ' . date('M j', strtotime($d['delivery_at']))) ?>">📅<span>Request new<br>pick-up date</span></button><small>by <?= e(date('M j', $gu)) ?></small>
              <?php else: ?>⌛ Time to ask for a new date ended. Message the DRRM Office.<?php endif; ?>
            </div>
          <?php elseif (empty($d['received_at']) && $d['delivery_status'] !== 'cancelled'): ?>
            <div class="rq-dlv-btns">
              <?php if (!rr_is_pickup($d) || $d['delivery_status'] === 'delivered'): ?><div class="rq-dlv-remind rq-dlv-got"><button type="button" data-got="<?= (int)$d['id'] ?>" data-got-title="<?= e($d['request_type'] . ' #' . $d['id']) ?>" title="I received the items: send a proof photo and add them to my inventory">✅<span>Received</span></button></div><?php endif; ?>
              <?php if ($bc !== 'delivered'): ?><form method="post" class="rq-dlv-remind"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit" name="remind_delivery" value="<?= (int)$d['id'] ?>" title="Save a reminder of this delivery in your Messages">🔔<span>Remind me</span></button></form><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="rq-modal" id="rqStorage" role="dialog" aria-modal="true" aria-labelledby="rqStorageTitle">
  <div class="rq-modal-box">
    <div class="rq-modal-head">
      <h3 id="rqStorageTitle">🗂️ My Requests</h3>
      <button type="button" class="rq-modal-close" id="rqStorageClose" aria-label="Close">&times;</button>
    </div>
    <div class="rq-filter" role="group" aria-label="Filter requests">
      <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Declined'] as $value => $text): ?>
        <?php $n = $value === '' ? count($my_requests) : count(array_filter($my_requests, function ($r) use ($value) { return $r['status'] === $value; })); ?>
        <button type="button" class="rq-tab<?= $value === '' ? ' active' : '' ?>" data-rq-status="<?= e($value) ?>"><?= e($text) ?> (<?= $n ?>)</button>
      <?php endforeach; ?>
    </div>
    <div class="rq-split">
    <aside class="rq-cal" aria-label="Request calendar">
      <div class="rq-cal-head">
        <button type="button" class="rq-cal-nav" id="rqCalPrev" aria-label="Previous month">&lsaquo;</button>
        <strong id="rqCalTitle"></strong>
        <button type="button" class="rq-cal-nav" id="rqCalNext" aria-label="Next month">&rsaquo;</button>
      </div>
      <div class="rq-cal-grid" id="rqCalGrid"></div>
      <div class="rq-cal-key"><span><b style="background:#fff1e0;border:1px solid #f5c48a;"></b> Day you sent a request</span><span><b style="box-shadow:inset 0 0 0 2px #3f9b46;"></b> Today</span></div>
      <div class="rq-cal-showing"><span id="rqCalShowing">Showing all dates</span><button type="button" class="rq-cal-clear" id="rqCalClear" hidden>Show all</button></div>
    </aside>
    <div class="rq-modal-body">
      <p class="rq-none" id="rqNone" hidden>No requests match this day and filter.</p>
      <?php if (!$my_requests): ?>
        <p style="color:#667085;">You haven't sent any resource requests yet.</p>
      <?php endif; ?>
      <?php foreach ($my_requests as $r): ?>
        <div class="rq-req" data-status="<?= e($r['status']) ?>" data-date="<?= e(date('Y-m-d', strtotime($r['requested_at']))) ?>">
          <span class="rq-status <?= e($r['status']) ?>"><?= e($status_labels[$r['status']] ?? ucfirst($r['status'])) ?></span>
          <strong><?= e($r['request_type']) ?></strong> <small>#<?= (int)$r['id'] ?> · sent <?= e(date('M j, Y g:i A', strtotime($r['requested_at']))) ?></small><span class="rq-date-chip"><?= e(date('F Y', strtotime($r['requested_at']))) ?></span><br>
          <div class="rr-items-head"><strong>Items requested</strong><a class="rr-print" href="request_print.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" title="Open the request form, ready to print or save as PDF"><i class="fa-solid fa-print"></i> Print request</a></div>
          <?= rr_items_table_html($r['items_requested']) ?>
          <strong>Reason:</strong> <?= e($r['reason']) ?>
          <?php if (!empty($r['admin_reply']) && in_array($r['status'], ['approved', 'rejected'], true)): ?><br><strong style="color:<?= $r['status'] === 'rejected' ? '#b42318' : '#067647' ?>;"><?= $r['status'] === 'rejected' ? 'Reason for declining' : 'Note from the DRRM Office' ?>:</strong> <?= e($r['admin_reply']) ?><?php endif; ?>
          <?php if ($r['status'] === 'approved' && !empty($r['delivery_at'])): ?><div class="rq-dlv-line"><?= rr_is_pickup($r) ? '📦' : '🚚' ?> <strong><?= e(rr_method_label($r)) ?>:</strong> <?= e(rr_delivery_line($r)) ?><?= !empty($r['method_reason']) ? ' &middot; <em>instead of delivery: ' . e($r['method_reason']) . '</em>' : '' ?> <?php [$bc, $bl] = rr_delivery_badge($r); ?><span class="dlv-chip <?= e($bc) ?>"><?= e($bl) ?></span></div><?php endif; ?>
          <?php if ($r['reviewed_at'] && $r['status'] !== 'pending'): ?><br><small>Reviewed <?= e(date('M j, Y g:i A', strtotime($r['reviewed_at']))) ?><?= in_array($r['status'], ['approved', 'rejected'], true) ? ' · the DRRM Office reply is in <a href="messages.php">Messages</a>' : '' ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    </div>
  </div>
</div>

<script>
(function () {
  // Item list: section buttons, search, "Selected" view, and a running summary of what will be requested.
  const rows = Array.prototype.slice.call(document.querySelectorAll('#rqItems .rq-item'));
  const sectionRows = document.querySelectorAll('#rqItems .rq-section-row');
  const search = document.getElementById('rqSearch');
  let section = '';
  function qtyOf(row) { const v = parseInt(row.querySelector('.rq-qty').value, 10); return isNaN(v) || v < 0 ? 0 : v; }
  function refresh() {
    const term = search.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(function (row) {
      const picked = qtyOf(row) > 0;
      row.classList.toggle('picked', picked);
      const ok = (!section || (section === '__picked' ? picked : row.dataset.section === section)) && (!term || row.dataset.name.indexOf(term) !== -1);
      row.hidden = !ok;
      if (ok) shown++;
    });
    sectionRows.forEach(function (sr) {
      sr.hidden = !rows.some(function (r) { return !r.hidden && r.dataset.section === sr.dataset.section; });
    });
    const none = document.getElementById('rqNoItems');
    none.hidden = shown > 0;
    none.firstElementChild.textContent = section === '__picked' && !term
      ? 'No items selected yet. Click "All" and enter a quantity next to the items you need.'
      : 'No items match.';
    const picked = rows.filter(function (r) { return qtyOf(r) > 0; });
    const units = picked.reduce(function (s, r) { return s + qtyOf(r); }, 0);
    document.getElementById('rqSummary').innerHTML = picked.length
      ? '<strong>' + picked.length + ' item' + (picked.length === 1 ? '' : 's') + '</strong> selected · ' + units + ' unit' + (units === 1 ? '' : 's') + ' in total'
      : 'No items selected yet.';
  }
  document.querySelectorAll('[data-rq-section]').forEach(function (b) {
    b.addEventListener('click', function () {
      section = b.dataset.rqSection;
      document.querySelectorAll('[data-rq-section]').forEach(function (x) { x.classList.toggle('active', x === b); });
      refresh();
    });
  });
  search.addEventListener('input', refresh);
  document.getElementById('rqItems').addEventListener('input', refresh);
  refresh();

  // My Requests storage popup with status filter.
  // Proof-of-delivery popup, opened from a delivery's Received button.
  const got = document.getElementById('rqGot'), gotFile = document.getElementById('rqGotFile'), gotPrev = document.getElementById('rqGotPreview');
  document.addEventListener('click', function (e) {
    const b = e.target.closest('[data-got]'); if (!b) return;
    document.getElementById('rqGotId').value = b.dataset.got;
    document.getElementById('rqGotFor').textContent = 'Delivery for ' + b.dataset.gotTitle;
    gotFile.value = ''; gotPrev.hidden = true; document.getElementById('rqGotPickText').hidden = false; document.getElementById('rqGotSubmit').disabled = true;
    got.classList.add('open');
  });
  gotFile.addEventListener('change', function () {
    const f = gotFile.files[0];
    document.getElementById('rqGotSubmit').disabled = !f;
    if (!f) { gotPrev.hidden = true; document.getElementById('rqGotPickText').hidden = false; return; }
    gotPrev.src = URL.createObjectURL(f); gotPrev.hidden = false; document.getElementById('rqGotPickText').hidden = true;
  });
  document.getElementById('rqGotForm').addEventListener('submit', function () { const s = document.getElementById('rqGotSubmit'); s.disabled = true; s.textContent = 'Sending…'; });
  function closeGot() { got.classList.remove('open'); }
  document.getElementById('rqGotClose').addEventListener('click', closeGot);
  got.addEventListener('click', function (e) { if (e.target === got) closeGot(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && got.classList.contains('open')) { closeGot(); e.stopImmediatePropagation(); } }, true);
  // New pick-up date after a miss.
  const miss = document.getElementById('rqMiss');
  document.addEventListener('click', function (e) {
    const b = e.target.closest('[data-miss]'); if (!b) return;
    document.getElementById('rqMissId').value = b.dataset.miss;
    document.getElementById('rqMissFor').textContent = b.dataset.missTitle;
    miss.classList.add('open');
  });
  document.getElementById('rqMissReason').addEventListener('change', function () { const o = document.getElementById('rqMissOther'); o.hidden = this.value !== 'other'; o.required = this.value === 'other'; });
  function closeMiss() { miss.classList.remove('open'); }
  document.getElementById('rqMissClose').addEventListener('click', closeMiss);
  miss.addEventListener('click', function (e) { if (e.target === miss) closeMiss(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && miss.classList.contains('open')) { closeMiss(); e.stopImmediatePropagation(); } }, true);
  // Deliveries popup (the truck button).
  const dlv = document.getElementById('rqDlv'), dlvBtn = document.getElementById('rqDlvOpen');
  dlvBtn.addEventListener('click', function () { dlv.classList.add('open'); document.getElementById('rqDlvClose').focus(); });
  function closeDlv() { dlv.classList.remove('open'); dlvBtn.focus(); }
  document.getElementById('rqDlvClose').addEventListener('click', closeDlv);
  dlv.addEventListener('click', function (e) { if (e.target === dlv) closeDlv(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && dlv.classList.contains('open')) closeDlv(); });
  const modal = document.getElementById('rqStorage');
  const openBtn = document.getElementById('rqStorageOpen');
  const closeBtn = document.getElementById('rqStorageClose');
  function open() { modal.classList.add('open'); closeBtn.focus(); }
  function close() { modal.classList.remove('open'); openBtn.focus(); }
  openBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) close(); });
  // Status tabs + calendar day work together: the calendar marks the days you sent requests (for the chosen status).
  const reqs = Array.prototype.slice.call(document.querySelectorAll('.rq-req'));
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  let statusF = '', dayF = '';
  const pad = function (n) { return (n < 10 ? '0' : '') + n; };
  const now = new Date(), todayKey = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
  const firstKey = reqs.length ? reqs[0].dataset.date : todayKey; // newest request first
  let calY = +firstKey.slice(0, 4), calM = +firstKey.slice(5, 7) - 1;
  function nice(key) { const d = new Date(key + 'T00:00:00'); return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
  function apply() {
    let shown = 0;
    reqs.forEach(function (r) {
      const ok = (!statusF || r.dataset.status === statusF) && (!dayF || r.dataset.date === dayF);
      r.hidden = !ok; if (ok) shown++;
    });
    document.getElementById('rqNone').hidden = shown > 0 || !reqs.length;
    document.getElementById('rqCalShowing').textContent = dayF ? 'Showing ' + nice(dayF) : 'Showing all dates';
    document.getElementById('rqCalClear').hidden = !dayF;
    renderCal();
  }
  function renderCal() {
    const counts = {};
    reqs.forEach(function (r) { if (!statusF || r.dataset.status === statusF) counts[r.dataset.date] = (counts[r.dataset.date] || 0) + 1; });
    document.getElementById('rqCalTitle').textContent = months[calM] + ' ' + calY;
    let h = '';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function (w) { h += '<div class="rq-cal-wd">' + w + '</div>'; });
    const start = new Date(calY, calM, 1).getDay(), days = new Date(calY, calM + 1, 0).getDate();
    for (let i = 0; i < start; i++) h += '<span></span>';
    for (let d = 1; d <= days; d++) {
      const key = calY + '-' + pad(calM + 1) + '-' + pad(d), n = counts[key] || 0;
      const cls = 'rq-cal-day' + (n ? ' has' : '') + (key === dayF ? ' sel' : '') + (key === todayKey ? ' today' : '');
      h += n ? '<button type="button" class="' + cls + '" data-day="' + key + '" title="' + n + ' request' + (n > 1 ? 's' : '') + ' on ' + nice(key) + '">' + d + '<i>' + n + ' req</i></button>'
             : '<span class="' + cls + '">' + d + '</span>';
    }
    const grid = document.getElementById('rqCalGrid');
    grid.innerHTML = h;
    grid.querySelectorAll('[data-day]').forEach(function (b) {
      b.addEventListener('click', function () { dayF = dayF === b.dataset.day ? '' : b.dataset.day; apply(); });
    });
  }
  document.getElementById('rqCalPrev').addEventListener('click', function () { if (--calM < 0) { calM = 11; calY--; } renderCal(); });
  document.getElementById('rqCalNext').addEventListener('click', function () { if (++calM > 11) { calM = 0; calY++; } renderCal(); });
  document.getElementById('rqCalClear').addEventListener('click', function () { dayF = ''; apply(); });
  document.querySelectorAll('[data-rq-status]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-rq-status]').forEach(function (x) { x.classList.toggle('active', x === b); });
      statusF = b.dataset.rqStatus; apply();
    });
  });
  apply();

})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
(function () {
  // Resource request spreadsheet: download a template of every item, import a filled one, or export this form.
  // Everything runs in the browser; nothing is sent until "Send request to DRRM Office" is clicked.
  var form = document.getElementById('rqForm');
  if (!form) return;
  var box = document.getElementById('rqImportResult');
  var typeSel = document.getElementById('rqType'), reasonEl = document.getElementById('rqReason'), otherEl = document.getElementById('rqOther');
  var MAX = 9999;

  function items() {
    return Array.prototype.map.call(document.querySelectorAll('#rqItems .rq-item'), function (row) {
      var input = row.querySelector('.rq-qty');
      return {
        key: input.id.replace(/^qty_/, ''),
        label: row.querySelector('label').textContent.trim(),
        section: row.dataset.section,
        have: row.querySelector('.rq-have').textContent.replace(/\s*·.*$/, '').trim(),
        input: input
      };
    });
  }
  function norm(s) { return String(s == null ? '' : s).toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').trim(); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function show(kind, html) {
    box.className = 'rq-import-result ' + kind; box.hidden = false;
    box.innerHTML = '<button type="button" aria-label="Dismiss">&times;</button>' + html;
    box.querySelector('button').addEventListener('click', function () { box.hidden = true; });
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function ready() {
    if (typeof XLSX === 'undefined') { show('err', '<strong>The spreadsheet tool did not load.</strong> Check the internet connection and refresh the page.'); return false; }
    return true;
  }

  // ---- Template / export: sheet "Request Items" (one row per item) + sheet "Request Details" ----
  function build(withValues) {
    var list = items();
    var rows = [['Section', 'Key', 'Item', 'You Have', 'Request Qty']];
    list.forEach(function (it) {
      var q = parseInt(it.input.value, 10);
      rows.push([it.section, it.key, it.label, it.have === '—' ? '' : (isNaN(+it.have) ? it.have : +it.have), withValues && q > 0 ? q : '']);
    });
    var ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{ wch: 18 }, { wch: 24 }, { wch: 34 }, { wch: 10 }, { wch: 12 }];
    var types = Array.prototype.map.call(typeSel.options, function (o) { return o.value ? o.textContent : null; }).filter(Boolean);
    var det = XLSX.utils.aoa_to_sheet([
      ['Field', 'Value', 'Notes'],
      ['Request type', withValues ? typeSel.value : '', 'One of: ' + types.join(', ')],
      ['Other items', withValues ? otherEl.value : '', 'Optional: anything not on the list, e.g. 20 sandbags, 2 generator sets'],
      ['Reason', withValues ? reasonEl.value : '', 'Why the items are needed']
    ]);
    det['!cols'] = [{ wch: 14 }, { wch: 50 }, { wch: 70 }];
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Request Items');
    XLSX.utils.book_append_sheet(wb, det, 'Request Details');
    return wb;
  }
  var today = new Date().toISOString().slice(0, 10);
  document.getElementById('rqTemplateBtn').addEventListener('click', function () {
    if (!ready()) return;
    XLSX.writeFile(build(false), 'resource-request-template-' + today + '.xlsx');
  });

  // ---- Import ----
  function headerIdx(h, res) { for (var i = 0; i < h.length; i++) { var t = norm(h[i]); if (res.some(function (r) { return r.test(t); })) return i; } return -1; }
  function importBook(wb) {
    var list = items();
    var itemSheet = wb.Sheets['Request Items'] || wb.Sheets[wb.SheetNames[0]];
    var rows = XLSX.utils.sheet_to_json(itemSheet, { header: 1, defval: '', raw: false });
    var QTY = [/^request qty/, /^request quantity/, /^qty/, /^quantity/, /^needed/, /^request/];
    var h = rows.findIndex(function (r) { return headerIdx(r, QTY) !== -1; });
    if (h === -1) { show('err', '<strong>Could not read this file.</strong> It needs a <em>Request Qty</em> column. Use <strong>Download Template</strong> to get the right layout.'); return; }
    var head = rows[h], iQ = headerIdx(head, QTY), iK = headerIdx(head, [/^key$/, /^code$/]), iN = headerIdx(head, [/^item/, /^name/, /^equipment/, /^description/]);
    document.querySelectorAll('.imported').forEach(function (el) { el.classList.remove('imported'); });
    var filled = 0, units = 0, bad = [], extra = [];
    list.forEach(function (it) { it.input.value = ''; });
    rows.slice(h + 1).forEach(function (r) {
      var key = iK !== -1 ? norm(r[iK]).replace(/ /g, '_') : '', name = iN !== -1 ? String(r[iN]).trim() : '';
      var raw = String(r[iQ] == null ? '' : r[iQ]).replace(/,/g, '').trim();
      if (!key && !name) return;
      if (raw === '' || raw === '0') return;
      var it = list.find(function (x) { return key && x.key === key; }) || list.find(function (x) { return norm(x.label) === norm(name); })
        || list.find(function (x) { return norm(name).length > 3 && norm(x.label).replace(/ /g, '').indexOf(norm(name).replace(/ /g, '')) === 0; });
      var q = Number(raw);
      if (!isFinite(q) || q < 0 || Math.floor(q) !== q || q > MAX) { bad.push((name || key) + ' ("' + raw + '")'); return; }
      if (!it) { extra.push(q + ' ' + (name || key)); return; }
      it.input.value = String(q); it.input.classList.add('imported'); filled++; units += q;
    });
    // Details sheet (optional)
    var det = wb.Sheets['Request Details'], detNote = [];
    if (det) {
      XLSX.utils.sheet_to_json(det, { header: 1, defval: '', raw: false }).forEach(function (r) {
        var field = norm(r[0]), val = String(r[1] == null ? '' : r[1]).trim();
        if (!val) return;
        if (field === 'request type') {
          var opt = Array.prototype.find.call(typeSel.options, function (o) { return o.value && norm(o.textContent) === norm(val); });
          if (opt) { typeSel.value = opt.value; typeSel.classList.add('imported'); detNote.push('request type'); }
          else bad.push('Request type "' + val + '" (not one of the choices)');
        } else if (field === 'reason') { reasonEl.value = val.slice(0, 1000); reasonEl.classList.add('imported'); detNote.push('reason'); }
        else if (field === 'other items') { otherEl.value = val.slice(0, 500); otherEl.classList.add('imported'); detNote.push('other items'); }
      });
    }
    if (extra.length) {
      var joined = (otherEl.value.trim() ? otherEl.value.trim() + ', ' : '') + extra.join(', ');
      otherEl.value = joined.slice(0, 500); otherEl.classList.add('imported');
    }
    document.getElementById('rqItems').dispatchEvent(new Event('input', { bubbles: true }));
    var html = filled || extra.length || detNote.length
      ? '<strong>✅ Imported ' + filled + ' item' + (filled === 1 ? '' : 's') + ' (' + units + ' unit' + (units === 1 ? '' : 's') + ').</strong> '
        + (detNote.length ? 'Also filled: ' + detNote.join(', ') + '. ' : '')
        + 'Imported values are highlighted in orange. Check them, then press <strong>Send request to DRRM Office</strong>.'
      : '<strong>No quantities were found in this file.</strong> Fill in the <em>Request Qty</em> column, then import it again.';
    if (extra.length) html += '<br><strong>Not on the list, added to "Other items":</strong> ' + esc(extra.join(', '));
    if (bad.length) html += '<br><strong>Skipped (quantity must be a whole number from 0 to ' + MAX + '):</strong> ' + esc(bad.slice(0, 8).join('; '));
    show(!filled && !extra.length && !detNote.length ? 'err' : (bad.length ? 'warn' : 'ok'), html);
  }
  document.getElementById('rqImportFile').addEventListener('change', function () {
    var input = this, file = input.files && input.files[0];
    input.value = '';
    if (!file || !ready()) return;
    if (file.size > 5 * 1024 * 1024) { show('err', '<strong>The file is too large.</strong> Please use a file under 5 MB.'); return; }
    var reader = new FileReader();
    reader.onload = function (e) {
      try { importBook(XLSX.read(new Uint8Array(e.target.result), { type: 'array' })); }
      catch (err) { show('err', '<strong>Could not read this file.</strong> Save it as .xlsx or .csv and try again.'); }
    };
    reader.readAsArrayBuffer(file);
  });
})();
</script>
</body>
</html>
