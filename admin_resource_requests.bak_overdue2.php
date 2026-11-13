<?php
require_once 'init.php';
require_login();
require_role(['admin']); // DRRMC Admin only; Head Admin does not process local requests
require_once __DIR__ . '/resource_request_lib.php';
$request_notice = '';
$request_error = '';
$admin_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
  $request_id = (int)($_POST['request_id'] ?? 0);
  if ($request_id < 1) {
    $request_error = 'Invalid resource request.';
  } else {
    $delete_stmt = $conn->prepare("UPDATE resource_requests SET status='deleted', reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status <> 'deleted'");
    if (!$delete_stmt) {
      $request_error = 'Unable to prepare request deletion.';
    } else {
      $delete_stmt->bind_param('ii', $admin_id, $request_id);
      $delete_stmt->execute();
      if ($delete_stmt->affected_rows === 1) audit_log('RESOURCE_REQUEST_DELETED', 'request #' . $request_id, 'Moved to storage');
      $request_notice = $delete_stmt->affected_rows === 1 ? 'Request deleted and saved in storage.' : 'This request was already deleted.';
      $delete_stmt->close();
    }
  }
}

// The DRRM Office's note / reason for each decision (shown to the barangay under My Requests).
$rr_col = $conn->query("SHOW COLUMNS FROM resource_requests LIKE 'admin_reply'");
if ($rr_col && $rr_col->num_rows === 0) $conn->query('ALTER TABLE resource_requests ADD admin_reply TEXT NULL DEFAULT NULL');

require_once __DIR__ . '/training_time.php'; // time picker options

// Delivery date, time and place from the form. Returns [datetime, place, error].
// Delivery or pick-up from the form. $preferred = what the barangay asked for; a pick-up the barangay did not ask for needs a reason.
// Returns ['method', 'at', 'end', 'loc', 'reason', 'error'].
function rr_delivery_from_post($preferred = null) {
  $method = ($_POST['fulfillment'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
  $date = (string)($_POST['delivery_date'] ?? ''); $time = (string)($_POST['delivery_time'] ?? ''); $end = (string)($_POST['pickup_end_time'] ?? '');
  $loc = trim((string)($_POST['delivery_location'] ?? ''));
  $word = $method === 'pickup' ? 'pick-up' : 'delivery';
  $out = ['method' => $method, 'at' => null, 'end' => null, 'loc' => $loc, 'reason' => null, 'error' => null];
  $d = DateTime::createFromFormat('Y-m-d', $date);
  if (!$d || $d->format('Y-m-d') !== $date) return ['error' => 'Choose the ' . $word . ' date.'] + $out;
  if (!isset(training_time_options()[$time])) return ['error' => 'Choose the ' . $word . ' time.'] + $out;
  if ($date < date('Y-m-d')) return ['error' => 'The ' . $word . ' date cannot be in the past.'] + $out;
  if ($method === 'pickup' && (!isset(training_time_options()[$end]) || $end <= $time)) return ['error' => 'The pick-up "until" time must be later than the start time.'] + $out;
  if ($loc === '' || mb_strlen($loc) > 255) return ['error' => $method === 'pickup' ? 'Enter where the barangay picks up the items.' : 'Enter where the items will be delivered (e.g. the barangay hall).'] + $out;
  if ($method === 'pickup' && $preferred !== 'pickup') {
    $r = (string)($_POST['method_reason'] ?? '');
    $r = $r === 'other' ? trim((string)($_POST['method_reason_other'] ?? '')) : (in_array($r, rr_pickup_reasons(), true) ? $r : '');
    if ($r === '' || mb_strlen($r) > 255) return ['error' => 'Give the reason why the barangay has to pick up the items instead of delivery.'] + $out;
    $out['reason'] = $r;
  }
  $out['at'] = "$date $time:00";
  if ($method === 'pickup') $out['end'] = "$date $end:00";
  return $out;
}
// Message lines for a schedule: "🚚 Delivery: ..." or "📦 Pick-up instead of delivery. Reason: ... When / Where / bring your ID".
function rr_schedule_text(array $dv) {
  if ($dv['method'] === 'pickup') {
    return '📦 Pick-up' . ($dv['reason'] ? ' instead of delivery. Reason: ' . $dv['reason'] : ' at the DRRM Office')
      . "\nWhen: " . date('l, F j, Y', strtotime($dv['at'])) . ', ' . date('g:i A', strtotime($dv['at'])) . ' - ' . date('g:i A', strtotime($dv['end']))
      . "\nWhere: " . $dv['loc'] . "\nBring your staff ID (the one you registered with) and the printed request form.";
  }
  return '🚚 Delivery: ' . rr_delivery_when($dv['at']) . "\nWhere: " . $dv['loc'];
}
// The method + schedule fields (approval form and reschedule form). The script below shows the pick-up parts and the reason when needed.
function rr_schedule_fields($pref, $method, $date, $time, $end, $deliver_to, $loc, $reason = '') {
  $opt = function ($sel) { $h = ''; foreach (training_time_options() as $k => $l) $h .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>'; return $h; };
  $is_other = $reason !== '' && !in_array($reason, rr_pickup_reasons(), true);
  $reasons = '<option value="">Choose a reason</option>';
  foreach (rr_pickup_reasons() as $r) $reasons .= '<option' . ($r === $reason ? ' selected' : '') . '>' . e($r) . '</option>';
  $reasons .= '<option value="other"' . ($is_other ? ' selected' : '') . '>Other (type the reason)</option>';
  return '<div class="dlv-method" data-pref="' . e((string)$pref) . '" data-deliver-to="' . e($deliver_to) . '" data-pickup-at="' . e(rr_pickup_place()) . '">'
    . '<div class="dlv-pick"><label><input type="radio" name="fulfillment" value="delivery"' . ($method !== 'pickup' ? ' checked' : '') . '> 🚚 Delivery</label>'
    . '<label><input type="radio" name="fulfillment" value="pickup"' . ($method === 'pickup' ? ' checked' : '') . '> 📦 Pick-up at the DRRM Office</label></div>'
    . '<div class="dlv-fields"><label><span class="dl-only">Delivery date</span><span class="pk-only">Pick-up date</span><input type="date" name="delivery_date" min="' . e(date('Y-m-d')) . '" value="' . e($date) . '"></label>'
    . '<label><span class="dl-only">Time</span><span class="pk-only">From</span><select name="delivery_time">' . $opt($time) . '</select></label>'
    . '<label class="pk-only">Until<select name="pickup_end_time">' . $opt($end) . '</select></label>'
    . '<label class="dlv-wide"><span class="dl-only">Deliver to</span><span class="pk-only">Pick-up place</span><input type="text" name="delivery_location" maxlength="255" value="' . e($loc) . '"></label></div>'
    . '<div class="dlv-reason"><label>Why pick-up instead of delivery? <b>(required: the barangay asked for delivery)</b><select name="method_reason">' . $reasons . '</select></label>'
    . '<input type="text" name="method_reason_other" maxlength="255" placeholder="Type the reason" value="' . e($is_other ? $reason : '') . '"></div>'
    . '</div>';
}

// Delivery updates on an approved request: out for delivery, delivered, or a new schedule. Each one messages the barangay.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivery_action'])) {
  $rid = (int)($_POST['request_id'] ?? 0);
  $act = (string)$_POST['delivery_action'];
  $row = $conn->query('SELECT * FROM resource_requests WHERE id = ' . $rid . " AND status = 'approved'")->fetch_assoc();
  if (!csrf_valid($_POST['csrf'] ?? '')) {
    $request_error = 'Your session expired. Please try again.';
  } elseif (!$row) {
    $request_error = 'This request is no longer available.';
  } elseif ($act === 'schedule') {
    $dv = rr_delivery_from_post($row['preferred_method']);
    // An overdue delivery needs the reason it did not arrive (sent to the barangay and kept in the history).
    $late = rr_is_overdue($row); $delay = '';
    if ($late && !$dv['error']) {
      $delay = (string)($_POST['delay_reason'] ?? '');
      $delay = $delay === 'other' ? trim((string)($_POST['delay_reason_other'] ?? '')) : (in_array($delay, rr_delay_reasons(), true) ? $delay : '');
      if ($delay === '' || mb_strlen($delay) > 255) $dv['error'] = 'Give the reason why the delivery did not arrive on ' . date('M j', strtotime($row['delivery_at'])) . '.';
    }
    if ($dv['error']) { $request_error = $dv['error']; }
    else {
      $was = $row['delivery_at'] ? rr_method_label($row) . ' ' . rr_delivery_line($row) : '';
      if ($late) rr_history($rid, 'Rescheduled by the DRRM Office to ' . date('M j, Y g:i A', strtotime($dv['at'])) . ($dv['method'] === 'pickup' ? ' (changed to pick-up)' : '') . '. Reason for the delay: ' . $delay);
      if ($row['delivery_status'] === 'missed') rr_history($rid, 'Rescheduled by the DRRM Office to ' . date('M j, Y', strtotime($dv['at'])) . ($dv['method'] === 'pickup' ? ', ' . date('g:i A', strtotime($dv['at'])) . ' - ' . date('g:i A', strtotime($dv['end'])) : ' (delivery)'));
      $st = $conn->prepare("UPDATE resource_requests SET fulfillment = ?, delivery_at = ?, pickup_end = ?, delivery_location = ?, method_reason = ?, delivery_status = 'scheduled', delivered_at = NULL, remind_day_before_at = NULL, remind_today_at = NULL, missed_at = NULL, overdue_at = NULL, resched_date = NULL, resched_reason = NULL, resched_note = NULL, resched_at = NULL WHERE id = ?");
      $st->bind_param('sssssi', $dv['method'], $dv['at'], $dv['end'], $dv['loc'], $dv['reason'], $rid); $st->execute(); $st->close();
      $what = $dv['method'] === 'pickup' ? 'Pick-up' : 'Delivery';
      rr_message($row['user_id'], '📅 ' . $what . ($was ? ' rescheduled' : ' scheduled') . ': your resource request #' . $rid . ' (' . $row['request_type'] . ").\n\n"
        . ($late ? 'Sorry, the delivery did not arrive on ' . date('F j', strtotime($row['delivery_at'])) . '. Reason: ' . $delay . "\n\n" : '')
        . rr_schedule_text($dv) . ($was ? "\n(Previously: " . $was . ')' : '') . "\n\nYou will get a reminder the day before and on the day.\n\n📄 Request form: " . rr_form_link($rid) . "\n\n- DRRM Office", $admin_id, 0);
      audit_log('RESOURCE_DELIVERY_SCHEDULED', 'request #' . $rid, $what . ': ' . $dv['at'] . ' - ' . $dv['loc'] . ($dv['reason'] ? '; reason: ' . $dv['reason'] : '') . ($late ? '; late delivery, reason for the delay: ' . $delay : ''));
      $request_notice = $what . ' ' . ($was ? 'rescheduled' : 'scheduled') . ' and the barangay was messaged.';
    }
  } elseif (!$row['delivery_at']) {
    $request_error = 'Set the delivery schedule first.';
  } elseif ($act === 'out' && $row['delivery_status'] === 'scheduled' && !rr_is_pickup($row)) {
    $conn->query("UPDATE resource_requests SET delivery_status = 'out' WHERE id = " . $rid);
    rr_message($row['user_id'], '🚚 On the way: the items for your resource request #' . $rid . ' (' . $row['request_type'] . ") are out for delivery.\n\n"
      . 'Expected: ' . rr_delivery_when($row['delivery_at']) . "\nWhere: " . $row['delivery_location'] . "\n\nPlease have someone ready to receive them.\n\n- DRRM Office", $admin_id, 0);
    audit_log('RESOURCE_DELIVERY_OUT', 'request #' . $rid, $row['delivery_location']);
    $request_notice = 'Marked as out for delivery; the barangay was messaged.';
  } elseif ($act === 'claimed' && rr_is_pickup($row) && in_array($row['delivery_status'], ['scheduled', 'missed'], true)) {
    // Pick-up: the registered staff (staff ID on file) or a representative with an authorization letter claimed the items.
    $rep = ($_POST['claim_who'] ?? '') === 'rep';
    $staff = $conn->query('SELECT fullname FROM users WHERE id = ' . (int)$row['user_id'])->fetch_assoc();
    $who = $rep ? trim((string)($_POST['rep_name'] ?? '')) : (string)($staff['fullname'] ?? '');
    if (empty($_POST['id_checked'])) { $request_error = 'Check the ID' . ($rep ? ' and the authorization letter' : '') . ' first, then tick the box.'; }
    elseif ($who === '' || mb_strlen($who) > 150) { $request_error = 'Enter the name of the representative who claimed the items.'; }
    else {
      $rep_i = $rep ? 1 : 0;
      if ($row['delivery_status'] === 'missed') rr_history($rid, 'Claimed late (after the missed pick-up)');
      $st = $conn->prepare("UPDATE resource_requests SET delivery_status = 'delivered', delivered_at = NOW(), claimed_by_name = ?, claimed_rep = ?, released_by = ?, missed_at = NULL WHERE id = ?");
      $st->bind_param('siii', $who, $rep_i, $admin_id, $rid); $st->execute(); $st->close();
      rr_message($row['user_id'], '🤝 Claimed: the items for your resource request #' . $rid . ' (' . $row['request_type'] . ') were picked up at ' . $row['delivery_location'] . ' on ' . date('F j, Y g:i A')
        . ' by ' . $who . ($rep ? ' (representative, with authorization letter)' : ' (staff ID checked)') . ".\n\n"
        . "Please count the items, then open Resource Requests > 🚚 Deliveries and tap ✅ Received: they are added to your Main Inventory automatically.\n\n- DRRM Office", $admin_id, 0);
      audit_log('RESOURCE_PICKUP_CLAIMED', 'request #' . $rid, 'Claimed by ' . $who . ($rep ? ' (representative with authorization letter)' : ' (registered staff, ID checked)') . ' at ' . $row['delivery_location']);
      $request_notice = 'Marked as claimed by ' . $who . '; the barangay was messaged.';
    }
  } elseif ($act === 'excuse' && $row['delivery_status'] === 'missed' && !$row['miss_excused']) {
    // A valid reason (e.g. a typhoon): this miss does not count toward the cancel limit.
    $conn->query('UPDATE resource_requests SET miss_excused = 1, missed_count = GREATEST(missed_count - 1, 0) WHERE id = ' . $rid);
    rr_history($rid, 'Miss excused by the DRRM Office' . ($row['resched_reason'] ? ' (reason: ' . $row['resched_reason'] . ')' : ''));
    rr_message($row['user_id'], '✔️ Excused: the missed pick-up for your resource request #' . $rid . " will not count against your barangay.\n\n- DRRM Office", $admin_id, 0);
    audit_log('RESOURCE_PICKUP_EXCUSED', 'request #' . $rid, (string)($row['resched_reason'] ?? 'no reason given yet'));
    $request_notice = 'Miss excused; it no longer counts toward the cancel limit.';
  } elseif ($act === 'approve_resched' && $row['delivery_status'] === 'missed' && !empty($row['resched_date'])) {
    // The barangay's new date, same time window as before.
    $at = $row['resched_date'] . ' ' . date('H:i:s', strtotime($row['delivery_at']));
    $end = $row['resched_date'] . ' ' . date('H:i:s', strtotime($row['pickup_end'] ?: $row['delivery_at']));
    $st = $conn->prepare("UPDATE resource_requests SET delivery_at = ?, pickup_end = ?, delivery_status = 'scheduled', missed_at = NULL, remind_day_before_at = NULL, remind_today_at = NULL, resched_date = NULL, resched_note = NULL, resched_at = NULL WHERE id = ?");
    $st->bind_param('ssi', $at, $end, $rid); $st->execute(); $st->close();
    rr_history($rid, 'New pick-up date approved: ' . date('M j, Y', strtotime($at)) . ', ' . date('g:i A', strtotime($at)) . ' - ' . date('g:i A', strtotime($end)));
    rr_message($row['user_id'], '📅 New pick-up date approved: your resource request #' . $rid . ' (' . $row['request_type'] . ").\n\n"
      . 'When: ' . date('l, F j, Y', strtotime($at)) . ', ' . date('g:i A', strtotime($at)) . ' - ' . date('g:i A', strtotime($end)) . "\nWhere: " . $row['delivery_location']
      . "\nBring your staff ID and the printed request form: " . rr_form_link($rid) . "\n\n- DRRM Office", $admin_id, 0);
    audit_log('RESOURCE_PICKUP_RESCHEDULED', 'request #' . $rid, 'New date ' . $row['resched_date'] . '; barangay reason: ' . $row['resched_reason']);
    $request_notice = 'New pick-up date approved; the barangay was messaged.';
  } elseif ($act === 'cancel_unclaimed' && rr_can_cancel($row)) {
    $why = trim((string)($_POST['cancel_note'] ?? ''));
    $conn->query("UPDATE resource_requests SET delivery_status = 'cancelled' WHERE id = " . $rid);
    rr_history($rid, 'Cancelled - unclaimed after ' . (int)$row['missed_total'] . ' missed pick-up(s); items returned to the city stock' . ($why !== '' ? ' (' . $why . ')' : ''));
    rr_message($row['user_id'], '❌ Cancelled: your resource request #' . $rid . ' (' . $row['request_type'] . ') was cancelled because the items were not picked up ('
      . (int)$row['missed_total'] . " missed pick-up(s)). The items were returned to the city stock.\n\n" . ($why !== '' ? 'Note: ' . $why . "\n\n" : '')
      . "You can send a new request if you still need these items.\n\n- DRRM Office", $admin_id, 0);
    audit_log('RESOURCE_PICKUP_CANCELLED', 'request #' . $rid, 'Unclaimed after ' . (int)$row['missed_total'] . ' missed pick-up(s)' . ($why !== '' ? '; ' . $why : ''));
    $request_notice = 'Request cancelled as unclaimed; the barangay was messaged.';
  } elseif ($act === 'delivered' && in_array($row['delivery_status'], ['scheduled', 'out'], true) && !rr_is_pickup($row)) {
    if (rr_is_overdue($row)) rr_history($rid, 'Delivered late (scheduled ' . date('M j, Y', strtotime($row['delivery_at'])) . ')');
    $conn->query("UPDATE resource_requests SET delivery_status = 'delivered', delivered_at = NOW() WHERE id = " . $rid);
    rr_message($row['user_id'], '📦 Delivered: the items for your resource request #' . $rid . ' (' . $row['request_type'] . ') were delivered to ' . $row['delivery_location'] . ' on ' . date('F j, Y g:i A') . ".\n\n"
      . "Please count the items, then open Resource Requests > 🚚 Deliveries and tap ✅ Received: they are added to your Main Inventory automatically.\n\n- DRRM Office", $admin_id, 0);
    audit_log('RESOURCE_DELIVERED', 'request #' . $rid, $row['delivery_location']);
    $request_notice = 'Marked as delivered; the barangay was messaged.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_request'])) {
  $request_id = (int)($_POST['request_id'] ?? 0);
  $request_user_id = (int)($_POST['request_user_id'] ?? 0);
  $admin_message = trim((string)($_POST['admin_message'] ?? ''));
  $request_status = ($_POST['request_status'] ?? '') === 'rejected' ? 'rejected' : 'approved';

  if ($request_id < 1 || $request_user_id < 1) {
    $request_error = 'This request is no longer available.';
  } elseif ($request_status === 'rejected' && $admin_message === '') {
    $request_error = 'Enter the reason for declining so the barangay knows why.';
  } elseif ($request_status === 'approved' && ($dv = rr_delivery_from_post(($conn->query('SELECT preferred_method FROM resource_requests WHERE id = ' . $request_id)->fetch_assoc() ?: [])['preferred_method'] ?? null)) && $dv['error']) {
    $request_error = 'To approve: ' . $dv['error'];
  } else {
    if ($request_status !== 'approved') $dv = ['method' => null, 'at' => null, 'end' => null, 'loc' => null, 'reason' => null];
    // The message to the barangay is written automatically from the decision.
    $req_info = $conn->query('SELECT request_type, items_requested FROM resource_requests WHERE id = ' . $request_id)->fetch_assoc() ?: ['request_type' => '', 'items_requested' => ''];
    $note = $admin_message;
    $admin_message = ($request_status === 'approved'
        ? "✅ Confirmed: your resource request #$request_id (" . $req_info['request_type'] . ") is APPROVED by the DRRM Office."
        : "❌ Your resource request #$request_id (" . $req_info['request_type'] . ") was DECLINED by the DRRM Office.")
      . ($request_status === 'approved' ? "\n\n" . rr_schedule_text($dv) . "\nYou will get a reminder the day before and on the day." : '')
      . ($request_status === 'rejected' ? "\n\nReason: " . $note : ($note !== '' ? "\n\nNote: " . $note : ''))
      . "\n\n📄 All requested items (ready to print / save as PDF): " . rr_form_link($request_id) // the item list is in the form, not the message
      . "\n\n- DRRM Office";
    $conn->begin_transaction();
    try {
      $status_stmt = $conn->prepare("UPDATE resource_requests SET status=?, admin_reply=?, reviewed_at=NOW(), reviewed_by=?, fulfillment=?, delivery_at=?, pickup_end=?, delivery_location=?, method_reason=?, delivery_status=IF(? = 'approved', 'scheduled', NULL) WHERE id=? AND user_id=? AND status='pending'");
      if (!$status_stmt) throw new RuntimeException('Unable to prepare request update.');
      $note_db = $note !== '' ? $note : null;
      $status_stmt->bind_param('ssissssssii', $request_status, $note_db, $admin_id, $dv['method'], $dv['at'], $dv['end'], $dv['loc'], $dv['reason'], $request_status, $request_id, $request_user_id);
      $status_stmt->execute();
      if ($status_stmt->affected_rows !== 1) throw new RuntimeException('This request was already reviewed or is no longer available.');
      $status_stmt->close();

      $message_stmt = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'admin', ?)");
      if (!$message_stmt) throw new RuntimeException('Unable to prepare message.');
      $message_stmt->bind_param('iis', $request_user_id, $admin_id, $admin_message);
      if (!$message_stmt->execute()) throw new RuntimeException('Unable to save the message.');
      $message_stmt->close();
      $conn->commit();
      audit_log('RESOURCE_REQUEST_' . strtoupper($request_status), 'request #' . $request_id, 'Requesting user #' . $request_user_id . ($note !== '' ? '; ' . ($request_status === 'rejected' ? 'reason' : 'note') . ': ' . $note : ''));
      $request_notice = ($request_status === 'approved' ? 'Request approved' : 'Request declined') . ' and message sent to the barangay user.';
    } catch (Throwable $exception) {
      $conn->rollback();
      $request_error = $exception->getMessage();
    }
  }
}

$requests = $conn->query("SELECT rr.*, u.fullname, u.barangay, u.position
  FROM resource_requests rr
  INNER JOIN users u ON u.id = rr.user_id
  ORDER BY rr.requested_at DESC, rr.id DESC");
$all_request_rows = [];
while ($request = $requests->fetch_assoc()) $all_request_rows[] = $request;
$request_rows = array_values(array_filter($all_request_rows, function ($request) {
  return $request['status'] !== 'deleted';
}));
$stored_requests = array_values(array_filter($all_request_rows, function ($request) {
  return $request['status'] !== 'pending' && !empty($request['reviewed_at']);
}));
usort($stored_requests, function ($first, $second) {
  return strcmp($second['reviewed_at'], $first['reviewed_at']);
});
$storage_data = array_map(function ($request) {
  $parsed = rr_parse_items($request['items_requested']);
  return [
    'date' => date('Y-m-d', strtotime($request['reviewed_at'])),
    'request_type' => $request['request_type'],
    'barangay' => barangay_label($request['barangay']),
    'brgy' => preg_replace('/\D+/', '', (string)$request['barangay']),
    'fullname' => $request['fullname'],
    'id' => (int)$request['id'],
    'items_requested' => $request['items_requested'],
    'items_html' => rr_items_table_html($request['items_requested']), // already escaped on the server
    'requested' => date('M j, Y g:i A', strtotime($request['requested_at'])),
    'reviewed' => date('M j, Y g:i A', strtotime($request['reviewed_at'])),
    'reason' => (string)$request['reason'],
    'note' => (string)($request['admin_reply'] ?? ''),
    'item_count' => array_sum(array_map('count', $parsed['sections'])) + count($parsed['other']),
    'units' => $parsed['total'],
    'delivery' => !empty($request['delivery_at']) ? rr_method_label($request) . ': ' . rr_delivery_line($request) . (!empty($request['method_reason']) ? ' (instead of delivery: ' . $request['method_reason'] . ')' : '')
      . (rr_is_pickup($request) && !empty($request['claimed_by_name']) ? ' - claimed by ' . $request['claimed_by_name'] . (!empty($request['claimed_rep']) ? ' (representative)' : '') : '') : '',
    'delivery_status' => rr_delivery_badge($request)[1],
    'delivery_class' => !empty($request['delivery_at']) ? rr_delivery_badge($request)[0] : '',
    'proof' => !empty($request['proof_message_id']) ? 'message_image.php?id=' . (int)$request['proof_message_id'] : '',
    'received' => !empty($request['received_at']) ? date('M j, Y g:i A', strtotime($request['received_at'])) . ' - ' . (int)$request['received_units'] . ' units added to the Main Inventory' : '',
    'status' => $request['status']
  ];
}, $stored_requests);

// Everything below is shown directly on the page, so no clicking is needed to see each barangay's data.
$status_totals = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$overview = [];
foreach ($request_rows as $request) { // newest first, so the first row seen per barangay is its latest
  if (isset($status_totals[$request['status']])) $status_totals[$request['status']]++;
  $key = preg_replace('/\D+/', '', (string)$request['barangay']);
  if ($key === '') $key = (string)$request['barangay'];
  if (!isset($overview[$key])) {
    $overview[$key] = ['label' => barangay_label($request['barangay']), 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'last' => $request['requested_at'], 'last_type' => $request['request_type']];
  }
  if (isset($overview[$key][$request['status']])) $overview[$key][$request['status']]++;
}
uasort($overview, function ($a, $b) {
  return [$b['pending'], $b['last']] <=> [$a['pending'], $a['last']];
});
$card_rows = $request_rows;
usort($card_rows, function ($a, $b) { // pending first, then newest
  $pa = $a['status'] === 'pending' ? 0 : 1;
  $pb = $b['status'] === 'pending' ? 0 : 1;
  return [$pa, $b['requested_at']] <=> [$pb, $a['requested_at']];
});
// Every request grouped by barangay, in the same order as the "Requests by barangay" list (for the Show all popup).
$all_by_barangay = [];
foreach ($overview as $key => $row) $all_by_barangay[(string)$key] = ['label' => $row['label'], 'requests' => []];
foreach ($card_rows as $request) {
  $key = preg_replace('/\D+/', '', (string)$request['barangay']);
  if ($key === '') $key = (string)$request['barangay'];
  if (isset($all_by_barangay[$key])) $all_by_barangay[$key]['requests'][] = $request;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Resource Requests</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .resource-page-shell { flex:1; min-width:0; min-height:0; height:100vh; }
    .resource-scroll { min-height:0; overflow-y:auto; padding:20px 24px; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    .resource-scroll::-webkit-scrollbar { width:10px; }
    .resource-scroll::-webkit-scrollbar-track { background:#eef1f4; }
    .resource-scroll::-webkit-scrollbar-thumb { background:#9aa7b5; border:2px solid #eef1f4; border-radius:5px; }

    /* top bar: totals + search */
    .rr-top { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
    .rr-chip { display:flex; align-items:baseline; gap:6px; padding:8px 14px; border-radius:10px; background:#fff; border:1px solid #e5e7eb; font-size:12px; color:#667085; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .rr-chip b { font-size:20px; color:#1f2937; letter-spacing:0; }
    .rr-chip.pending b { color:#b45309; } .rr-chip.approved b { color:#059669; } .rr-chip.rejected b { color:#dc2626; }
    .rr-search { flex:1; min-width:220px; max-width:420px; margin-left:auto; padding:10px 12px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:13px; background:#fff; }
    .rr-filter-pill { display:none; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; background:#e8f4ef; color:#1e4d37; font-size:12px; font-weight:700; }
    .rr-filter-pill button { border:0; background:transparent; color:#1e4d37; font-size:15px; cursor:pointer; line-height:1; }

    /* two columns that use the whole screen: requests on the left, calendar + stored data on the right */
    .rr-grid { display:grid; grid-template-columns:minmax(0,1fr) 400px; gap:20px; align-items:start; }
    .rr-panel { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .rr-panel-head { display:flex; justify-content:space-between; align-items:baseline; gap:10px; padding:14px 16px 8px; }
    .rr-panel-head h3 { margin:0; font-size:15px; color:#1f2937; }
    .rr-panel-head span { font-size:12px; color:#667085; }
    .rr-side { position:sticky; top:0; max-height:calc(100vh - 150px); overflow-y:auto; }

    /* requests by barangay */
    .rr-overview { margin-bottom:18px; }
    .rr-overview-scroll { max-height:230px; overflow-y:auto; padding:0 6px 8px; }
    .rr-table { width:100%; border-collapse:collapse; font-size:12px; }
    .rr-table th { position:sticky; top:0; background:#f8fafb; padding:8px 10px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#667085; }
    .rr-table td { padding:8px 10px; border-top:1px solid #eef0f2; color:#344054; }
    .rr-table tr.rr-row { cursor:pointer; }
    .rr-table tr.rr-row:hover td { background:#f8fafb; }
    .rr-table tr.rr-row.active td { background:#e8f4ef; }
    .rr-num { text-align:center; font-weight:700; }
    .rr-num.zero { color:#c0c6cf; font-weight:500; }
    .rr-num.pending { color:#b45309; }
    .rr-title { margin:0 0 10px; font-size:15px; color:#1f2937; }

    /* barangay list (left) and its requests (right), side by side */
    .rr-split { display:grid; grid-template-columns:minmax(280px, 360px) minmax(0, 1fr); gap:18px; align-items:start; }
    .rr-split .rr-overview { position:sticky; top:0; margin-bottom:0; display:flex; flex-direction:column; max-height:calc(100vh - 250px); }
    .rr-split .rr-overview-scroll { max-height:none; flex:1; min-height:0; }
    .rr-split .rr-panel-head { flex-wrap:wrap; }
    .rr-row .rr-latest { display:block; margin-top:2px; font-size:11px; font-weight:400; color:#667085; }
    .rr-table tr.rr-row:focus { outline:none; }
    .rr-table tr.rr-row:focus-visible td { box-shadow:inset 0 0 0 2px #ff8c00; }
    .rr-table tr.rr-row.active td { background:#fff7ed; }
    .rr-table tr.rr-row.active td:first-child { box-shadow:inset 4px 0 0 #ff8c00; }
    .rr-requests-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin:0 0 10px; padding:12px 16px; border-radius:12px; background:rgba(255,255,255,.94); box-shadow:0 4px 14px rgba(120,60,0,.1); }
    .rr-requests-head .rr-title { margin:0; }
    .rr-requests-head .rr-count { margin-left:4px; color:#667085; font-weight:500; font-size:13px; }
    .rr-show-all { display:none; border:1px solid #f5c48a; background:#fff; color:#c56a00; border-radius:8px; padding:6px 12px; font:inherit; font-size:12px; font-weight:700; cursor:pointer; }
    .rr-show-all:hover { background:#fff7ed; }
    .rr-split .rr-cards { grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); }
    .rr-show-all { display:inline-block; }
    .rr-pager { display:flex; align-items:center; gap:8px; min-width:0; }
    .rr-page-btn { width:32px; height:32px; flex-shrink:0; border:1px solid #f5c48a; border-radius:50%; background:#fff; color:#c56a00; font-size:20px; line-height:1; cursor:pointer; }
    .rr-page-btn:hover:not(:disabled), .rr-page-btn:focus-visible { background:#fff7ed; outline:none; }
    .rr-page-btn:disabled { opacity:.4; cursor:default; }
    .rr-split .rr-cards { grid-template-columns:1fr; }
    .rr-brgy-body { padding:16px 20px; overflow-y:auto; display:flex; flex-direction:column; gap:14px; }
    .rr-brgy-body .request-card { box-shadow:none; }
    .rr-all-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.55); }
    .rr-all-modal.open { display:flex; }
    .rr-all-box { width:min(880px,100%); max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .rr-all-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; }
    .rr-all-head h3 { margin:0; padding:0; background:none; color:#fff; font-size:17px; }
    .rr-all-head p { margin:2px 0 0; font-size:12px; opacity:.9; }
    .rr-all-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; padding:0 4px; border-radius:4px; }
    .rr-all-close:hover, .rr-all-close:focus { background:rgba(255,255,255,.2); outline:none; }
    .rr-all-search { margin:14px 20px 0; padding:9px 12px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:13px; }
    .rr-all-search:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .rr-all-body { padding:14px 20px; overflow-y:auto; display:grid; grid-template-columns:repeat(auto-fill, minmax(250px,1fr)); gap:12px; align-content:start; }
    .rr-all-group { border:1px solid #f0e6da; border-radius:10px; overflow:hidden; background:#fff; }
    .rr-all-group.current { border-color:#ff8c00; box-shadow:0 0 0 2px rgba(255,140,0,.25); }
    .rr-all-group-head { width:100%; display:flex; justify-content:space-between; align-items:center; gap:8px; padding:10px 12px; border:0; background:#fff7ed; color:#9a4a00; font:inherit; font-size:14px; font-weight:800; text-align:left; cursor:pointer; }
    .rr-all-group-head:hover, .rr-all-group-head:focus { background:#ffedd5; outline:none; }
    .rr-all-group-head small { font-size:11px; font-weight:600; color:#c56a00; white-space:nowrap; }
    .rr-all-item { padding:9px 12px; border-top:1px solid #f5efe7; font-size:12px; color:#667085; line-height:1.45; }
    .rr-all-item strong { color:#1f2937; font-size:13px; }
    .rr-all-item .request-status { float:right; margin-left:6px; padding:2px 8px; font-size:10px; }
    .rr-all-foot { display:flex; justify-content:flex-end; gap:10px; padding:12px 20px; border-top:1px solid #f0f0f0; }
    .rr-all-foot button { padding:9px 16px; border-radius:8px; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .rr-all-reset { border:0; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; }
    .rr-all-cancel { border:1px solid #d0d5dd; background:#fff; color:#344054; }
    .rr-all-none { display:none; grid-column:1 / -1; text-align:center; color:#667085; padding:20px; }
    .request-card.flash { animation:rrFlash 1.2s ease-out; }
    @keyframes rrFlash { 0% { box-shadow:0 0 0 3px #ff8c00; } 100% { box-shadow:0 2px 8px rgba(16,24,40,.06); } }
    @media (prefers-reduced-motion: reduce) { .request-card.flash { animation:none; } }
    /* Theme gives every "section h3" a gradient bar; these headings should stay plain. */
    .layout section .rr-panel-head h3, .layout section .rr-title, .layout section .request-header h3 { background:none; padding:0; border-radius:0; color:#1f2937; }
    @media (max-width:900px) {
      .rr-split { grid-template-columns:1fr; }
      .rr-split .rr-overview { position:static; max-height:320px; }
    }

    /* request cards fill the width in a grid */
    .rr-cards { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:14px; align-items:start; }
    .request-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .request-card.is-pending { border-left:4px solid #f59e0b; }
    .request-header { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:10px; }
    .request-header h3 { margin:0; color:#1f2937; font-size:14px; line-height:1.3; }
    .request-status { display:inline-block; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }
    .status-pending { background:#fef3c7; color:#92400e; }
    .status-approved { background:#d1fae5; color:#065f46; }
    .status-rejected { background:#fee2e2; color:#991b1b; }
    .status-deleted { background:#f2f4f7; color:#475467; }
    .request-body { color:#6b7280; font-size:13px; line-height:1.5; }
    .request-body p { margin:0 0 5px; }
    .btn-small { padding:8px 12px; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; }
    .btn-approve { background:#10b981; color:#fff; }
    .btn-reject { background:#ef4444; color:#fff; }
    .dlv-ack { display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; background:#eef4ff; color:#3538cd; font-size:11px; font-weight:700; }
    .dlv-box { margin:10px 0; padding:10px 12px; border:1px solid #f5c48a; border-radius:10px; background:#fffaf4; font-size:12.5px; }
    .dlv-box > span { color:#667085; font-size:11.5px; }
    .dlv-fields { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
    .dlv-fields label { display:grid; gap:3px; font-size:11px; font-weight:700; color:#9a4a00; text-transform:uppercase; letter-spacing:.3px; }
    .dlv-fields input, .dlv-fields select { width:100%; box-sizing:border-box; padding:7px 9px; border:1px solid #d9e1dc; border-radius:6px; font:inherit; font-size:12.5px; text-transform:none; letter-spacing:0; color:#1f2937; font-weight:400; }
    .dlv-fields .dlv-wide { grid-column:1 / -1; }
    .dlv-pref { margin:6px 0 0; color:#344054; }
    .dlv-pick { display:flex; flex-wrap:wrap; gap:6px 14px; margin-top:8px; }
    .dlv-pick label { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border:1px solid #f5c48a; border-radius:8px; background:#fff; font-weight:700; color:#9a4a00; cursor:pointer; }
    .dlv-pick label:has(input:checked) { background:#fff1e0; border-color:#ea7a00; }
    .dlv-method .pk-only { display:none; } .dlv-method.is-pickup .pk-only { display:grid; } .dlv-method.is-pickup span.pk-only { display:inline; } .dlv-method.is-pickup .dl-only { display:none; }
    .dlv-reason { display:none; margin-top:8px; padding:8px 10px; border:1px dashed #f79009; border-radius:8px; background:#fffaeb; }
    .dlv-method.needs-reason .dlv-reason { display:grid; gap:6px; }
    .dlv-reason label { display:grid; gap:4px; font-size:11px; font-weight:700; color:#b54708; text-transform:uppercase; letter-spacing:.3px; }
    .dlv-reason label b { text-transform:none; letter-spacing:0; font-weight:600; }
    .dlv-delay { display:grid; gap:6px; margin:8px 0; padding:8px 10px; border:1px dashed #d92d20; border-radius:8px; background:#fef3f2; }
    .dlv-delay label { display:grid; gap:4px; font-size:11px; font-weight:700; color:#b42318; text-transform:uppercase; letter-spacing:.3px; }
    .dlv-delay label b { text-transform:none; letter-spacing:0; font-weight:600; }
    .dlv-delay select, .dlv-delay input, .dlv-reason select, .dlv-reason input { width:100%; box-sizing:border-box; padding:7px 9px; border:1px solid #d9e1dc; border-radius:6px; font:inherit; font-size:12.5px; }
    .dlv-chip.missed { background:#fef3f2; color:#b42318; } .dlv-chip.cancelled { background:#f2f4f7; color:#667085; }
    .dlv-missed { margin:8px 0 2px; padding:8px 10px; border:1px solid #fda29b; border-radius:8px; background:#fef3f2; color:#7a271a; }
    .dlv-missed p { margin:0 0 5px; }
    .dlv-excuse { background:#eef4ff; color:#3538cd; }
    .dlv-note { padding:6px 8px; border:1px solid #d9e1dc; border-radius:6px; font:inherit; font-size:12px; margin-right:4px; }
    .dlv-hist { margin-top:6px; } .dlv-hist summary { cursor:pointer; color:#475467; font-weight:700; font-size:12px; }
    .dlv-hist ul { margin:4px 0 0; padding-left:18px; color:#475467; font-size:12px; line-height:1.6; }
    .dlv-why { margin:4px 0 0; color:#b54708; font-weight:600; }
    .dlv-claim summary { color:#067647; }
    .dlv-who { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:6px 0; font-size:12.5px; color:#344054; text-transform:none !important; letter-spacing:0 !important; font-weight:400; }
    .dlv-who input[type=radio], .dlv-who input[type=checkbox], .dlv-pick input[type=radio] { width:auto !important; margin:0; flex:0 0 auto; }
    .dlv-pick label { text-transform:none !important; letter-spacing:0 !important; }
    .dlv-who b { font-weight:800; }
    .dlv-who input[type=text] { flex:1; min-width:180px; padding:6px 8px; border:1px solid #d9e1dc; border-radius:6px; font:inherit; font-size:12.5px; }
    .dlv-who a { color:#2d7a5c; font-weight:700; }
    .dlv-row { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .dlv-chip { padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; }
    .dlv-chip.scheduled { background:#eef4ff; color:#3538cd; } .dlv-chip.out { background:#fef3c7; color:#92400e; }
    .dlv-chip.delivered { background:#e0f2fe; color:#075985; } .dlv-chip.received { background:#d1fae5; color:#065f46; } .dlv-chip.none { background:#f2f4f7; color:#475467; }
    .dlv-received { margin:6px 0 0; padding:6px 9px; border-radius:7px; background:#ecfdf3; color:#067647; font-weight:700; }
    .dlv-proof { display:flex; align-items:center; gap:10px; margin:8px 0 2px; padding:6px; border:1px solid #abefc6; border-radius:10px; background:#fff; text-decoration:none; color:#067647; font-weight:700; font-size:12.5px; }
    .dlv-proof img { width:84px; height:64px; object-fit:cover; border-radius:7px; background:#f2f4f7; }
    .dlv-proof small { color:#667085; font-weight:400; }
    .dlv-proof:hover { background:#ecfdf3; }
    .dlv-wait { margin:6px 0 0; color:#b54708; font-weight:600; }
    .storage-pick .dlv-chip { margin-left:4px; vertical-align:middle; }
    .dlv-when { margin:6px 0 0; color:#344054; } .dlv-when em { color:#c2410c; font-style:normal; font-weight:700; }
    .dlv-actions { display:flex; flex-wrap:wrap; align-items:flex-start; gap:6px; margin-top:8px; }
    .dlv-actions form { margin:0; }
    .dlv-out { background:#f59e0b; color:#fff; }
    .dlv-resched { width:100%; } .dlv-resched summary { cursor:pointer; color:#c2410c; font-weight:700; font-size:12px; padding:4px 0; }
    .dlv-resched form { margin-top:4px; } .dlv-resched .btn-small { margin-top:8px; }
    .request-message-form { margin-top:12px; padding-top:12px; border-top:1px solid #eef0f2; }
    .request-message-form textarea { width:100%; min-height:56px; resize:vertical; padding:8px 10px; border:1px solid #d9e1dc; border-radius:5px; font:inherit; font-size:12px; }
    .request-message-actions { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px; flex-wrap:wrap; }
    .rr-items-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin:10px 0 6px; font-size:13px; }
    .rr-print { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border:1px solid #f5c48a; border-radius:8px; background:#fff7ed; color:#9a4a00; font-size:12px; font-weight:700; text-decoration:none; white-space:nowrap; }
    .rr-print:hover { background:#ffedd5; }
    .rr-items { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:10px; }
    .rr-items th, .rr-items td { padding:5px 8px; border-bottom:1px solid #eaecf0; text-align:left; }
    .rr-items th { font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:#667085; background:#f9fafb; }
    .rr-items th:last-child, .rr-items td:last-child { width:70px; text-align:right; font-variant-numeric:tabular-nums; }
    .rr-items tr.rr-sec td, .rr-items tr.rr-sec td:last-child { width:auto; text-align:left; padding-top:8px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; color:#c2410c; background:#fffaf5; }
    .rr-items tfoot td { font-weight:800; color:#1f2937; border-bottom:0; border-top:2px solid #eaecf0; }
    .rr-items tbody tr:not(.rr-sec):hover td { background:#f9fafb; }
    .request-message-link { color:#2d7a5c; font-size:12px; font-weight:700; text-decoration:none; }
    .request-message-link:hover { text-decoration:underline; }
    .rr-caught { display:none; padding:34px 24px; text-align:center; background:#fff; border-radius:12px; box-shadow:0 4px 14px rgba(120,60,0,.1); }
    .rr-caught-icon { width:64px; height:64px; margin:0 auto 12px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:30px; color:#fff; background:linear-gradient(135deg,#3f9b46,#2d7a5c); box-shadow:0 6px 16px rgba(63,155,70,.3); }
    .rr-caught h4 { margin:0 0 6px; font-size:19px; color:#1f2937; }
    .rr-caught p { margin:0 auto 16px; max-width:380px; font-size:13.5px; line-height:1.55; color:#667085; }
    .rr-caught-stats { display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin:0 0 18px; }
    .rr-caught-stats span { padding:6px 14px; border-radius:999px; font-size:12.5px; font-weight:700; }
    .rr-caught-stats .ok { background:#d1fae5; color:#065f46; } .rr-caught-stats .no { background:#fee2e2; color:#991b1b; }
    .rr-browse { border:0; border-radius:8px; padding:10px 18px; font:inherit; font-size:13.5px; font-weight:700; color:#fff; cursor:pointer; background:linear-gradient(135deg,#ea7a00,#3f9b46); box-shadow:0 4px 12px rgba(234,122,0,.25); }
    .rr-browse:hover, .rr-browse:focus { filter:brightness(1.08); outline:none; }
    .rr-back { display:none; border:1px solid #d0d5dd; background:#fff; color:#475467; border-radius:8px; padding:6px 12px; font:inherit; font-size:12px; font-weight:700; cursor:pointer; }
    .rr-back:hover { background:#f9fafb; }
    .rr-kind { display:inline-block; margin-right:6px; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:700; vertical-align:middle; }
    .rr-kind.pending { background:#fef3c7; color:#92400e; } .rr-kind.reviewed { background:#eef2f6; color:#475467; }
    .rr-empty { display:none; grid-column:1 / -1; padding:26px; text-align:center; color:#667085; background:#fff; border:1px dashed #d0d5dd; border-radius:12px; }

    /* calendar (always visible) */
    .calendar-heading { display:flex; justify-content:space-between; align-items:center; padding:0 16px 8px; }
    .calendar-title { flex:1; margin:0 6px; padding:7px 10px; border:0; border-radius:8px; background:transparent; color:#1f2937; font:inherit; font-size:15px; font-weight:800; cursor:pointer; }
    .calendar-title:hover:not(:disabled) { background:#f2f4f7; }
    .calendar-title:disabled { cursor:default; }
    .calendar-grid.tiles { grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; }
    .calendar-tile { padding:16px 6px; border:1px solid #eaecf0; border-radius:10px; background:#fff; color:#344054; font:inherit; font-size:14px; font-weight:700; text-align:center; cursor:pointer; }
    .calendar-tile:hover { border-color:#1f5f4a; }
    .calendar-tile.has-requests { background:#ecfdf3; color:#065f46; }
    .calendar-tile.current { box-shadow:inset 0 0 0 2px #93c5fd; }
    .calendar-tile.selected { border-color:#1f5f4a; box-shadow:inset 0 0 0 2px #1f5f4a; }
    .calendar-nav { border:1px solid #d0d5dd; background:#fff; color:#1f5f4a; border-radius:5px; padding:4px 10px; cursor:pointer; }
    .calendar-week, .calendar-grid { display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); gap:4px; padding:0 16px; }
    .calendar-week { color:#667085; font-size:10px; font-weight:700; text-align:center; margin-bottom:4px; }
    .calendar-day { min-height:44px; border:1px solid #eaecf0; background:#fff; border-radius:6px; padding:5px; text-align:left; cursor:pointer; color:#344054; font-size:12px; }
    .calendar-day:hover, .calendar-day.selected { border-color:#1f5f4a; box-shadow:inset 0 0 0 1px #1f5f4a; }
    .calendar-day.is-empty { background:#f9fafb; cursor:default; border-color:#f2f4f7; }
    .calendar-day.has-requests { background:#ecfdf3; color:#065f46; font-weight:700; }
    .calendar-day-count { display:block; margin-top:2px; font-size:10px; color:#1f5f4a; }
    .storage-head { display:flex; justify-content:space-between; align-items:center; gap:8px; margin:14px 16px 6px; padding-top:12px; border-top:1px solid #eaecf0; }
    .storage-head strong { color:#1f5f4a; font-size:13px; }
    .storage-head button { border:0; background:transparent; color:#2d7a5c; font-size:12px; font-weight:700; cursor:pointer; }
    .storage-list { padding:14px 20px 18px; overflow-y:auto; background:#f9fafb; }
    .storage-tip { margin:12px 16px 8px; font-size:12px; color:#667085; text-align:center; }
    .storage-open { display:flex; align-items:center; justify-content:center; gap:8px; width:calc(100% - 32px); margin:0 16px 16px; padding:11px 14px; border:0; border-radius:10px; font:inherit; font-size:14px; font-weight:700; color:#fff; cursor:pointer; background:linear-gradient(135deg,#ea7a00,#3f9b46); box-shadow:0 4px 12px rgba(234,122,0,.25); }
    .storage-open:hover, .storage-open:focus { filter:brightness(1.08); outline:none; }
    .storage-all { border:1px solid rgba(255,255,255,.7); background:rgba(255,255,255,.18); color:#fff; border-radius:8px; padding:6px 12px; font:inherit; font-size:12px; font-weight:700; cursor:pointer; }
    #rrStoreModal .storage-item { margin:0 0 10px; padding:12px 14px; background:#fff; border:1px solid #eaecf0; border-left:4px solid #f5c48a; border-radius:10px; font-size:13px; }
    #rrStoreModal .storage-date { margin:6px 0 8px; }
    .rr-req-modal { z-index:1100; }
    .storage-pick { display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; margin:0 0 8px; padding:11px 14px; background:#fff; border:1px solid #eaecf0; border-left:4px solid #f5c48a; border-radius:10px; font:inherit; font-size:13px; color:#344054; text-align:left; cursor:pointer; transition:background .15s, border-color .15s; }
    .storage-pick:hover, .storage-pick:focus-visible { background:#fff7ed; border-color:#f5c48a; outline:none; }
    .storage-pick strong { color:#1f2937; }
    .storage-pick .request-status { margin-left:8px; }
    .storage-pick small { display:block; margin-top:3px; color:#667085; font-size:12px; }
    .storage-pick .sp-go { flex-shrink:0; color:#c2410c; font-size:12.5px; font-weight:800; }
    .rq-detail-meta { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:10px; }
    .rq-detail-meta div { padding:8px 10px; border:1px solid #eaecf0; border-radius:8px; background:#f9fafb; font-size:13px; color:#1f2937; }
    .rq-detail-meta span { display:block; margin-bottom:3px; color:#667085; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
    .rq-detail-meta .request-status { display:inline-block; width:auto; margin:0; }
    .rq-detail-reason { margin:0 0 8px; font-size:13px; color:#475467; }
    @media (max-width:640px) { .rq-detail-meta { grid-template-columns:1fr; } }
    .storage-date { margin:10px 0 2px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#667085; }
    .storage-item { padding:9px 0; border-bottom:1px solid #f0f2f4; font-size:12px; color:#667085; line-height:1.45; }
    .storage-item strong { color:#1f2937; }
    .storage-item .request-status { margin-left:4px; }
    .storage-empty { color:#667085; font-size:13px; padding:12px 0; }

    @media (max-width:1150px) {
      .rr-grid { grid-template-columns:1fr; }
      .rr-side { position:static; max-height:none; }
      .resource-page-shell { height:auto; }
      .resource-scroll { overflow-y:visible; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="resource-page-shell" style="display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;"><h2 style="margin:0;font-size:24px;">Resource Requests</h2><p style="margin:4px 0 0;color:#666;font-size:13px;">Manage equipment and supply allocation requests from barangays.</p></header>
    <main class="main-content resource-scroll" style="flex:1;">
      <?php if ($request_notice): ?><div class="notice"><?= e($request_notice) ?></div><?php endif; ?>
      <?php $overdue_list = array_values(array_filter($request_rows, function ($r) { return $r['status'] === 'approved' && rr_is_overdue($r); })); ?>
      <?php if ($overdue_list): ?><div class="dlv-missed" style="margin:0 0 12px;"><b>⚠️ <?= count($overdue_list) ?> overdue deliver<?= count($overdue_list) === 1 ? 'y' : 'ies' ?>:</b> <?= e(implode(' · ', array_map(function ($r) { return barangay_label($r['barangay']) . ' #' . $r['id'] . ' (' . rr_overdue_text($r) . ')'; }, $overdue_list))) ?>. Open the request and set a new date with the reason.</div><?php endif; ?>
      <?php if ($request_error): ?><div class="errors"><?= e($request_error) ?></div><?php endif; ?>

      <div class="rr-top">
        <div class="rr-chip pending"><b><?= (int)$status_totals['pending'] ?></b> Pending</div>
        <div class="rr-chip approved"><b><?= (int)$status_totals['approved'] ?></b> Approved</div>
        <div class="rr-chip rejected"><b><?= (int)$status_totals['rejected'] ?></b> Declined</div>
        <div class="rr-chip"><b><?= count($stored_requests) ?></b> In storage</div>
        <div class="rr-filter-pill" id="rrFilterPill"><span id="rrFilterText"></span><button type="button" id="rrFilterClear" aria-label="Clear barangay filter">&times;</button></div>
        <input class="rr-search" id="rrSearch" type="search" placeholder="Search barangay, requester, item or status" aria-label="Search resource requests">
      </div>

      <div class="rr-grid">
        <section class="rr-split">
          <div class="rr-panel rr-overview">
            <div class="rr-panel-head"><h3>Requests by barangay</h3><span>Click a barangay to see its requests</span></div>
            <div class="rr-overview-scroll">
              <?php if (!$overview): ?>
                <p class="storage-empty">No resource requests yet.</p>
              <?php else: ?>
              <table class="rr-table">
                <thead><tr><th>Barangay</th><th class="rr-num">Pending</th><th class="rr-num">Approved</th><th class="rr-num">Declined</th></tr></thead>
                <tbody>
                  <?php foreach ($overview as $key => $row): ?>
                    <tr class="rr-row" tabindex="0" role="button" aria-pressed="false" data-brgy="<?= e((string)$key) ?>" data-label="<?= e($row['label']) ?>">
                      <td><strong><?= e($row['label']) ?></strong><span class="rr-latest">Latest: <?= e(date('M j', strtotime($row['last']))) ?> &middot; <?= e($row['last_type']) ?></span></td>
                      <td class="rr-num pending<?= $row['pending'] ? '' : ' zero' ?>"><?= (int)$row['pending'] ?></td>
                      <td class="rr-num<?= $row['approved'] ? '' : ' zero' ?>"><?= (int)$row['approved'] ?></td>
                      <td class="rr-num<?= $row['rejected'] ? '' : ' zero' ?>"><?= (int)$row['rejected'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>

          <div class="rr-requests" id="rrRequests">
          <div class="rr-requests-head">
            <div class="rr-pager">
              <button type="button" class="rr-page-btn" id="rrPrev" aria-label="Previous request">&lsaquo;</button>
              <h3 class="rr-title"><span id="rrRequestsTitle">Request</span> <span class="rr-count" id="rrRequestsCount"></span></h3>
              <button type="button" class="rr-page-btn" id="rrNext" aria-label="Next request">&rsaquo;</button>
            </div>
            <span style="display:flex;gap:8px;"><button type="button" class="rr-back" id="rrBack">&larr; Back</button><button type="button" class="rr-show-all" id="rrShowAll" aria-haspopup="dialog">Show all barangays</button></span>
          </div>
          <div class="rr-caught" id="rrCaught">
            <div class="rr-caught-icon">&#10003;</div>
            <h4>All caught up!</h4>
            <p>There are no pending requests right now. New requests from barangays will show up here automatically.</p>
          </div>
          <div class="rr-cards" id="requestList">
          <?php foreach ($card_rows as $request): ?>
            <?php $brgy_key = preg_replace('/\D+/', '', (string)$request['barangay']); if ($brgy_key === '') $brgy_key = (string)$request['barangay']; ?>
            <div class="request-card<?= $request['status'] === 'pending' ? ' is-pending' : '' ?>" data-brgy="<?= e($brgy_key) ?>" data-search="<?= e(strtolower($request['request_type'] . ' ' . $request['fullname'] . ' ' . barangay_label($request['barangay']) . ' ' . $request['items_requested'] . ' ' . $request['reason'] . ' ' . $request['status'])) ?>">
              <div class="request-header">
                <h3><?= e($request['request_type']) ?> - <?= e(barangay_label($request['barangay'])) ?></h3>
                <span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;"><?php if ($request['status'] === 'approved' && !empty($request['delivery_at'])): [$bc, $bl] = rr_delivery_badge($request); ?><span class="dlv-chip <?= e($bc) ?>"><?= e($bl) ?></span><?php endif; ?><span class="request-status status-<?= e($request['status']) ?>"><?= e(ucfirst($request['status'])) ?></span></span>
              </div>
              <div class="request-body">
                <p><strong>Requested by:</strong> <?= e($request['fullname']) ?> (<?= e($request['position']) ?>)</p>
                <p><strong>Date:</strong> <?= e(date('F j, Y', strtotime($request['requested_at']))) ?><?php if (!empty($request['acknowledged_at'])): ?> <span class="dlv-ack" title="The barangay was told automatically that the request was received">📥 Acknowledged <?= e(date('M j, g:i A', strtotime($request['acknowledged_at']))) ?></span><?php endif; ?></p>
                <div class="rr-items-head"><strong>Items requested</strong><a class="rr-print" href="request_print.php?id=<?= (int)$request['id'] ?>" target="_blank" rel="noopener" title="Open the request form, ready to print or save as PDF"><i class="fa-solid fa-print"></i> Print request</a></div>
                <?= rr_items_table_html($request['items_requested']) ?>
                <p><strong>Reason:</strong> <?= e($request['reason']) ?></p>
              </div>
              <?php if ($request['status'] === 'pending'): ?>
                <form method="post" class="request-message-form">
                  <input type="hidden" name="review_request" value="1">
                  <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                  <input type="hidden" name="request_user_id" value="<?= (int)$request['user_id'] ?>">
                  <label for="request_message_<?= (int)$request['id'] ?>" style="font-size:12px;font-weight:700;">Note / reason <span style="font-weight:400;color:#667085;">(optional when approving, <b style="color:#b42318;">required when declining</b>; the barangay is messaged automatically)</span></label>
                  <?php $pref = (string)($request['preferred_method'] ?? ''); $pk = $pref === 'pickup'; ?>
                  <div class="dlv-box"><strong>🚚 Delivery or 📦 pick-up</strong> <span>(required when approving; the barangay gets reminders the day before and on the day)</span>
                    <?php if ($pref): ?><p class="dlv-pref">Barangay asked for: <b><?= $pk ? '📦 Pick-up at the DRRM Office' : '🚚 Delivery' ?></b></p><?php endif; ?>
                    <?= rr_schedule_fields($pref, $pk ? 'pickup' : 'delivery', date('Y-m-d', strtotime('+1 day')), '09:00', '16:00', rr_default_location($request['barangay']), $pk ? rr_pickup_place() : rr_default_location($request['barangay'])) ?></div>
                  <textarea id="request_message_<?= (int)$request['id'] ?>" name="admin_message" class="review-note" placeholder="e.g. Ready for pick-up at the DRRM Office on Monday / Not enough stock this month"></textarea>
                  <div class="request-message-actions">
                    <a class="request-message-link" href="messages.php?user_id=<?= (int)$request['user_id'] ?>">Open conversation</a>
                    <div><button type="submit" name="request_status" value="approved" class="btn-small btn-approve">Approve &amp; Message</button> <button type="submit" name="request_status" value="rejected" class="btn-small btn-reject">Decline &amp; Message</button></div>
                  </div>
                </form>
              <?php else: ?>
                <?php if ($request['status'] === 'approved'): $ds = (string)($request['delivery_status'] ?? ''); ?>
                  <div class="dlv-box">
                    <?php [$bc, $bl] = rr_delivery_badge($request); ?><div class="dlv-row"><strong><?= rr_is_pickup($request) ? '📦 Pick-up' : '🚚 Delivery' ?></strong><span class="dlv-chip <?= e($bc) ?>"><?= e($bl) ?></span></div>
                    <?php if (!empty($request['delivery_at'])): ?>
                      <p class="dlv-when"><?= e(rr_delivery_line($request)) ?><?php if (rr_is_overdue($request)): ?> <em style="color:#b42318;">(<?= e(rr_overdue_text($request)) ?>)</em><?php elseif ($ds !== 'delivered'): ?> <em>(<?= e(rr_delivery_countdown($request['delivery_at'])) ?>)</em><?php endif; ?></p>
                      <?php if (rr_is_overdue($request)): ?><div class="dlv-missed"><p><b>⚠️ Not delivered</b> on <?= e(date('M j, Y', strtotime($request['delivery_at']))) ?>. The barangay was told the delivery is delayed.</p><p>Set a new date with the reason below (📅 Reschedule), or tap 📦 Mark delivered if the items did arrive.</p></div><?php endif; ?>
                      <?php if (!empty($request['method_reason'])): ?><p class="dlv-why">Barangay asked for delivery &rarr; pick-up. Reason: <?= e($request['method_reason']) ?></p><?php endif; ?>
                      <?php if ($ds === 'missed'): $gu = rr_grace_until($request); ?>
                        <div class="dlv-missed">
                          <p><b>⚠️ Not picked up</b> on <?= e(date('M j, Y', strtotime($request['delivery_at']))) ?> &middot; unexcused misses: <b><?= (int)$request['missed_count'] ?> of <?= RR_MAX_MISSES ?></b><?= $request['miss_excused'] ? ' &middot; this miss was excused' : '' ?></p>
                          <?php if (!empty($request['resched_date'])): ?>
                            <p>📅 Barangay asked for <b><?= e(date('l, F j, Y', strtotime($request['resched_date']))) ?></b><br>Reason: <b><?= e($request['resched_reason']) ?></b><?= !empty($request['resched_note']) ? '<br>Note: ' . e($request['resched_note']) : '' ?></p>
                          <?php elseif ($gu >= time()): ?><p>⏳ Waiting for the barangay to ask for a new date (until <?= e(date('M j, Y', $gu)) ?>).</p>
                          <?php else: ?><p>⌛ Grace period ended on <?= e(date('M j, Y', $gu)) ?> with no new date asked.</p><?php endif; ?>
                          <div class="dlv-actions">
                            <?php if (!empty($request['resched_date'])): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn-small btn-approve" name="delivery_action" value="approve_resched">✅ Approve new date</button></form><?php endif; ?>
                            <?php if (!$request['miss_excused']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn-small dlv-excuse" name="delivery_action" value="excuse" title="Valid reason (e.g. typhoon): this miss will not count">✔️ Excuse this miss</button></form><?php endif; ?>
                            <?php if (rr_can_cancel($request)): ?><form method="post" onsubmit="return confirm('Cancel this request as unclaimed? The barangay will be messaged and the items go back to the city stock.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="text" name="cancel_note" maxlength="200" placeholder="Note (optional)" class="dlv-note"><button class="btn-small btn-reject" name="delivery_action" value="cancel_unclaimed">❌ Cancel - unclaimed</button></form><?php endif; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                      <?php if ($ds === 'cancelled'): ?><p class="dlv-why">❌ Cancelled as unclaimed; the items were returned to the city stock.</p><?php endif; ?>
                      <?php if (rr_history_lines($request)): ?><details class="dlv-hist"><summary>🕘 Delivery / pick-up history (<?= count(rr_history_lines($request)) ?>)</summary><ul><?php foreach (rr_history_lines($request) as $hl): ?><li><?= e($hl) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
                      <?php if ($ds === 'delivered' && !empty($request['delivered_at'])): ?><p class="dlv-when"><?php if (rr_is_pickup($request)): ?>🤝 Claimed by <b><?= e((string)$request['claimed_by_name']) ?></b><?= !empty($request['claimed_rep']) ? ' (representative, authorization letter checked)' : ' (staff ID checked)' ?> on <?= e(date('F j, Y g:i A', strtotime($request['delivered_at']))) ?><?php else: ?>📦 Delivered <?= e(date('F j, Y g:i A', strtotime($request['delivered_at']))) ?><?php endif; ?></p><?php endif; ?>
                      <?php if (!empty($request['received_at'])): ?><p class="dlv-received">✅ Received by <?= e(barangay_label($request['barangay'])) ?> on <?= e(date('F j, Y g:i A', strtotime($request['received_at']))) ?> &middot; <?= (int)$request['received_units'] ?> unit<?= (int)$request['received_units'] === 1 ? '' : 's' ?> added to its Main Inventory</p>
                      <?php if (!empty($request['proof_message_id'])): ?><a class="dlv-proof" href="message_image.php?id=<?= (int)$request['proof_message_id'] ?>" target="_blank" rel="noopener" title="Open the proof-of-delivery photo"><img src="message_image.php?id=<?= (int)$request['proof_message_id'] ?>" alt="Proof of delivery" loading="lazy"><span>📷 Proof of delivery<br><small>Sent by the barangay &middot; click to enlarge</small></span></a><?php endif; ?>
                      <?php elseif ($ds === 'delivered'): ?><p class="dlv-wait">⏳ Waiting for the barangay to confirm receipt (✅ Received in their Deliveries).</p><?php endif; ?>
                    <?php else: ?><p class="dlv-when">No delivery schedule yet. Set one so the barangay knows when the items arrive.</p><?php endif; ?>
                    <?php if (!in_array($ds, ['delivered', 'cancelled'], true) && empty($request['received_at'])): ?>
                      <div class="dlv-actions">
                        <?php if (rr_is_pickup($request) && in_array($ds, ['scheduled', 'missed'], true)): ?>
                        <details class="dlv-resched dlv-claim"<?= $ds === 'scheduled' ? ' open' : '' ?>><summary>🤝 Mark claimed (items picked up)</summary>
                          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <label class="dlv-who"><input type="radio" name="claim_who" value="staff" checked> Registered staff: <b><?= e($request['fullname']) ?></b> <a href="view_id.php?id=<?= (int)$request['user_id'] ?>" target="_blank" rel="noopener">🪪 View staff ID on file</a></label>
                            <label class="dlv-who"><input type="radio" name="claim_who" value="rep"> Representative with an authorization letter: <input type="text" name="rep_name" maxlength="150" placeholder="Full name of the representative"></label>
                            <label class="dlv-who"><input type="checkbox" name="id_checked" value="1" required> I checked the ID (and the authorization letter for a representative) and the printed request form.</label>
                            <button class="btn-small btn-approve" name="delivery_action" value="claimed">🤝 Save &amp; message barangay</button>
                          </form>
                        </details>
                        <?php endif; ?>
                        <?php if (!rr_is_pickup($request) && $ds === 'scheduled' && !rr_is_overdue($request)): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn-small dlv-out" name="delivery_action" value="out">🚚 Out for delivery</button></form><?php endif; ?>
                        <?php if (!rr_is_pickup($request) && in_array($ds, ['scheduled', 'out'], true)): ?><form method="post" onsubmit="return confirm('Mark these items as delivered? The barangay will be messaged.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn-small btn-approve" name="delivery_action" value="delivered">📦 Mark delivered</button></form><?php endif; ?>
                        <details class="dlv-resched"<?= rr_is_overdue($request) ? ' open' : '' ?>><summary><?= !empty($request['delivery_at']) ? '📅 Reschedule / change to ' . (rr_is_pickup($request) ? 'delivery' : 'pick-up') : '📅 Set delivery or pick-up schedule' ?></summary>
                          <?php $dv_date = !empty($request['delivery_at']) ? date('Y-m-d', strtotime($request['delivery_at'])) : date('Y-m-d', strtotime('+1 day')); if ($dv_date < date('Y-m-d')) $dv_date = date('Y-m-d'); $dv_time = !empty($request['delivery_at']) ? date('H:i', strtotime($request['delivery_at'])) : '09:00'; $dv_loc = (string)($request['delivery_location'] ?: rr_default_location($request['barangay'])); $dv_end = !empty($request['pickup_end']) ? date('H:i', strtotime($request['pickup_end'])) : '16:00'; ?>
                          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><?= rr_schedule_fields((string)($request['preferred_method'] ?? ''), rr_is_pickup($request) ? 'pickup' : 'delivery', $dv_date, $dv_time, $dv_end, rr_default_location($request['barangay']), $dv_loc, (string)($request['method_reason'] ?? '')) ?>
                          <?php if (rr_is_overdue($request)): ?><div class="dlv-delay"><label>Why did the delivery not arrive on <?= e(date('M j', strtotime($request['delivery_at']))) ?>? <b>(required: sent to the barangay)</b><select name="delay_reason" required><option value="">Choose a reason</option><?php foreach (rr_delay_reasons() as $dr): ?><option><?= e($dr) ?></option><?php endforeach; ?><option value="other">Other (type the reason)</option></select></label><input type="text" name="delay_reason_other" maxlength="255" placeholder="Type the reason (if Other)"></div><?php endif; ?>
                          <button class="btn-small btn-approve" name="delivery_action" value="schedule">Save &amp; message barangay</button></form>
                        </details>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <div class="request-message-actions">
                  <span style="color:#667085;font-size:12px;">Reviewed <?= !empty($request['reviewed_at']) ? e(date('M j, Y', strtotime($request['reviewed_at']))) : '' ?>.</span>
                  <a class="request-message-link" href="messages.php?user_id=<?= (int)$request['user_id'] ?>">Open conversation</a>
                </div>
              <?php endif; ?>
              <form method="post" style="margin-top:8px;text-align:right;" onsubmit="return confirm('Delete this request and move it to storage?');">
                <input type="hidden" name="delete_request" value="1">
                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                <button type="submit" class="btn-small btn-reject"><i class="fa-solid fa-trash"></i> Delete Request</button>
              </form>
            </div>
          <?php endforeach; ?>
            <div class="rr-empty" id="cardsEmpty">No requests match your search or barangay filter.</div>
          </div>
          </div>
        </section>

        <aside class="rr-panel rr-side">
          <div class="rr-panel-head"><h3><i class="fa-solid fa-box-archive"></i> Request Storage</h3><span><?= count($stored_requests) ?> stored</span></div>
          <div class="calendar-heading">
            <button class="calendar-nav" id="previousMonth" type="button" aria-label="Previous">&laquo;</button>
            <button class="calendar-title" id="calendarTitle" type="button" title="Tap to choose a month or year"></button>
            <button class="calendar-nav" id="nextMonth" type="button" aria-label="Next">&raquo;</button>
          </div>
          <div class="calendar-week" id="calendarWeek"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
          <div class="calendar-grid" id="calendarGrid"></div>
          <p class="storage-tip">Click a highlighted day to see that day's requests.</p>
          <button type="button" class="storage-open" id="storageOpen"><i class="fa-solid fa-folder-open"></i> <span id="storageOpenText">View stored requests</span></button>
        </aside>
      </div>
    </main>

    <div class="rr-all-modal" id="rrStoreModal" role="dialog" aria-modal="true" aria-labelledby="storageHeading">
      <div class="rr-all-box" style="width:min(720px,100%);">
        <div class="rr-all-head">
          <div><h3 id="storageHeading">Stored requests</h3><p>Every request saved in Request Storage, newest first.</p></div>
          <span style="display:flex;align-items:center;gap:8px;"><button type="button" class="storage-all" id="storageShowAll" style="display:none;">Show all dates</button><button type="button" class="rr-all-close" id="rrStoreClose" aria-label="Close">&times;</button></span>
        </div>
        <div class="storage-list" id="dayRequests"></div>
      </div>
    </div>

    <div class="rr-all-modal rr-req-modal" id="rrReqModal" role="dialog" aria-modal="true" aria-labelledby="rrReqTitle">
      <div class="rr-all-box" style="width:min(720px,100%);">
        <div class="rr-all-head">
          <div><h3 id="rrReqTitle">Request</h3><p id="rrReqSub"></p></div>
          <span style="display:flex;align-items:center;gap:8px;"><button type="button" class="storage-all" id="rrReqBack">&lsaquo; Back to list</button><button type="button" class="rr-all-close" id="rrReqClose" aria-label="Close">&times;</button></span>
        </div>
        <div class="storage-list" id="rrReqBody"></div>
      </div>
    </div>

    <div class="rr-all-modal" id="rrBrgyModal" role="dialog" aria-modal="true" aria-labelledby="rrBrgyTitle">
      <div class="rr-all-box" style="width:min(680px,100%);">
        <div class="rr-all-head">
          <div>
            <h3 id="rrBrgyTitle">Barangay requests</h3>
            <p id="rrBrgySub">Review, approve or decline right here.</p>
          </div>
          <button type="button" class="rr-all-close" id="rrBrgyClose" aria-label="Close">&times;</button>
        </div>
        <div class="rr-brgy-body" id="rrBrgyBody"></div>
      </div>
    </div>

    <div class="rr-all-modal" id="rrAllModal" role="dialog" aria-modal="true" aria-labelledby="rrAllTitle">
      <div class="rr-all-box">
        <div class="rr-all-head">
          <div>
            <h3 id="rrAllTitle">All barangays &middot; <?= count($card_rows) ?> request<?= count($card_rows) === 1 ? '' : 's' ?></h3>
            <p>Click a barangay to jump to its requests.</p>
          </div>
          <button type="button" class="rr-all-close" id="rrAllClose" aria-label="Close">&times;</button>
        </div>
        <input type="search" class="rr-all-search" id="rrAllSearch" placeholder="Filter barangays, items or status" aria-label="Filter the list">
        <div class="rr-all-body" id="rrAllBody">
          <?php if (!$all_by_barangay): ?>
            <p class="storage-empty" style="grid-column:1 / -1;">No resource requests yet.</p>
          <?php endif; ?>
          <?php foreach ($all_by_barangay as $key => $group): ?>
            <div class="rr-all-group" data-brgy="<?= e((string)$key) ?>" data-search="<?= e(strtolower($group['label'] . ' ' . implode(' ', array_map(function ($r) { return $r['request_type'] . ' ' . $r['items_requested'] . ' ' . $r['status'] . ' ' . $r['fullname']; }, $group['requests'])))) ?>">
              <button type="button" class="rr-all-group-head" data-brgy="<?= e((string)$key) ?>" data-label="<?= e($group['label']) ?>">
                <span><?= e($group['label']) ?></span>
                <small><?= count($group['requests']) ?> request<?= count($group['requests']) === 1 ? '' : 's' ?> &rarr;</small>
              </button>
              <?php foreach ($group['requests'] as $r): ?>
                <div class="rr-all-item">
                  <span class="request-status status-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span>
                  <strong><?= e($r['request_type']) ?></strong><br>
                  <?= e(date('M j, Y', strtotime($r['requested_at']))) ?> &middot; <?= e($r['fullname']) ?><br>
                  <?= e($r['items_requested']) ?><br><a class="rr-print" href="request_print.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print request</a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <p class="rr-all-none" id="rrAllNone">No barangays match that filter.</p>
        </div>
        <div class="rr-all-foot">
          <button type="button" class="rr-all-cancel" id="rrAllCancel">Close</button>
          <button type="button" class="rr-all-reset" id="rrAllReset">Show all on page</button>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
  const storageRequests = <?= json_encode($storage_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const byId = function (id) { return document.getElementById(id); };
  let calendarDate = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let selectedDate = '';   // '' = every date
  let searchTerm = '';
  let browseReviewed = false; // with no pending requests, the reviewed ones are shown only after "View reviewed requests"
  let brgyFilter = '';     // barangay number, '' = all
  let brgyLabel = '';

  if (storageRequests.length) { // open the calendar on the month that has the newest stored request
    const newest = new Date(storageRequests[0].date + 'T00:00:00');
    calendarDate = new Date(newest.getFullYear(), newest.getMonth(), 1);
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]); });
  }
  function isVisible(request) {
    if (brgyFilter && request.brgy !== brgyFilter) return false;
    if (!searchTerm) return true;
    return [request.request_type, request.barangay, request.fullname, request.items_requested, request.status].join(' ').toLowerCase().includes(searchTerm);
  }
  function statusPill(status) {
    return '<span class="request-status status-' + escapeHtml(status) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span>';
  }

  // Phone-style calendar: days -> (tap the title) months -> (tap the title) years. Pick a year, then a month, then a day.
  const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  let calView = 'days';    // 'days' | 'months' | 'years'
  let yearPageStart = 0;   // first year shown in the years view (12 years per page)

  function requestCounts() { // filtered stored requests per year and per month
    const perYear = {}, perMonth = {};
    storageRequests.filter(isVisible).forEach(function (r) {
      const y = +r.date.slice(0, 4), m = +r.date.slice(5, 7) - 1;
      perYear[y] = (perYear[y] || 0) + 1;
      perMonth[y + '-' + m] = (perMonth[y + '-' + m] || 0) + 1;
    });
    return { perYear: perYear, perMonth: perMonth };
  }
  function tileHtml(label, count, classes, attributes) {
    return '<button type="button" class="calendar-tile ' + classes + '" ' + attributes + '>' + label +
      (count ? '<span class="calendar-day-count">' + count + ' req' + (count === 1 ? '' : 's') + '</span>' : '') + '</button>';
  }

  function renderCalendar() {
    const year = calendarDate.getFullYear(), month = calendarDate.getMonth();
    const grid = byId('calendarGrid'), title = byId('calendarTitle');
    const now = new Date();
    byId('calendarWeek').style.display = calView === 'days' ? '' : 'none';
    title.disabled = calView === 'years';

    if (calView === 'years') {
      const counts = requestCounts().perYear;
      title.textContent = yearPageStart + ' – ' + (yearPageStart + 11);
      grid.className = 'calendar-grid tiles';
      let html = '';
      for (let y = yearPageStart; y < yearPageStart + 12; y++) {
        html += tileHtml(y, counts[y], (counts[y] ? 'has-requests ' : '') + (y === year ? 'selected ' : '') + (y === now.getFullYear() ? 'current' : ''), 'data-year="' + y + '"');
      }
      grid.innerHTML = html;
      return;
    }
    if (calView === 'months') {
      const counts = requestCounts().perMonth;
      title.textContent = year + ' ▾';
      grid.className = 'calendar-grid tiles';
      let html = '';
      for (let m = 0; m < 12; m++) {
        const c = counts[year + '-' + m];
        html += tileHtml(MONTH_SHORT[m], c, (c ? 'has-requests ' : '') + (m === month ? 'selected ' : '') + (year === now.getFullYear() && m === now.getMonth() ? 'current' : ''), 'data-month="' + m + '"');
      }
      grid.innerHTML = html;
      return;
    }

    title.textContent = MONTH_NAMES[month] + ' ' + year + ' ▾';
    grid.className = 'calendar-grid';
    const counts = {};
    storageRequests.filter(isVisible).forEach(function (r) { counts[r.date] = (counts[r.date] || 0) + 1; });
    grid.innerHTML = '';
    for (let i = 0; i < new Date(year, month, 1).getDay(); i++) grid.insertAdjacentHTML('beforeend', '<span class="calendar-day is-empty"></span>');
    for (let day = 1; day <= new Date(year, month + 1, 0).getDate(); day++) {
      const key = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
      const count = counts[key] || 0;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'calendar-day' + (count ? ' has-requests' : '') + (selectedDate === key ? ' selected' : '');
      button.innerHTML = day + (count ? '<span class="calendar-day-count">' + count + ' req' + (count === 1 ? '' : 's') + '</span>' : '');
      button.addEventListener('click', function () { selectedDate = key; renderCalendar(); renderList(); openStore(); });
      grid.appendChild(button);
    }
  }

  function renderList() {
    const matching = storageRequests.filter(isVisible);
    const shown = matching.filter(function (r) { return !selectedDate || r.date === selectedDate; });
    const scope = brgyFilter ? brgyLabel + ' · ' : '';
    byId('storageHeading').textContent = selectedDate
      ? scope + new Date(selectedDate + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) + ' (' + shown.length + ')'
      : scope + 'All stored requests (' + shown.length + ')';
    byId('storageShowAll').style.display = selectedDate ? '' : 'none';
    byId('storageOpenText').textContent = 'View stored requests (' + matching.length + ')';
    const list = byId('dayRequests');
    if (!shown.length) { list.innerHTML = '<div class="storage-empty">No stored requests ' + (selectedDate ? 'for this date' : 'match your filters') + '.</div>'; return; }
    let html = '', lastDate = '';
    shown.forEach(function (r) {
      if (!selectedDate && r.date !== lastDate) {
        lastDate = r.date;
        html += '<div class="storage-date">' + escapeHtml(new Date(r.date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })) + '</div>';
      }
      html += '<button type="button" class="storage-pick" data-req="' + r.id + '"><span class="sp-main"><strong>' + escapeHtml(r.barangay) + '</strong> &middot; ' + escapeHtml(r.request_type) + statusPill(r.status) + (r.delivery_class ? ' <span class="dlv-chip ' + r.delivery_class + '">' + escapeHtml(r.delivery_status) + '</span>' : '')
        + '<small>' + escapeHtml(r.fullname) + ' &middot; sent ' + escapeHtml(r.requested) + ' &middot; ' + r.item_count + ' item' + (r.item_count === 1 ? '' : 's') + ', ' + r.units + ' units</small></span><span class="sp-go">View &rsaquo;</span></button>';
    });
    list.innerHTML = html;
  }

  // The middle column shows ONE request at a time; the arrows step through every request that matches the filters.
  let cardIndex = 0;
  function applyFilters() {
    const matching = [];
    document.querySelectorAll('#requestList .request-card').forEach(function (card) {
      const ok = (!searchTerm || card.dataset.search.includes(searchTerm)) && (!brgyFilter || card.dataset.brgy === brgyFilter);
      card.style.display = 'none';
      if (ok) matching.push(card);
    });
    const hasPending = !!document.querySelector('#requestList .request-card.is-pending');
    const caught = !hasPending && !browseReviewed && !searchTerm && !brgyFilter;
    byId('rrCaught').style.display = caught ? 'block' : 'none';
    byId('requestList').style.display = caught ? 'none' : '';
    byId('rrPrev').style.display = byId('rrNext').style.display = caught ? 'none' : '';
    byId('rrBack').style.display = !hasPending && browseReviewed ? 'inline-block' : 'none';
    if (cardIndex >= matching.length) cardIndex = Math.max(0, matching.length - 1);
    if (matching.length) matching[cardIndex].style.display = '';
    const visibleCards = matching.length;
    byId('cardsEmpty').style.display = visibleCards ? 'none' : 'block';
    byId('rrPrev').disabled = cardIndex <= 0;
    byId('rrNext').disabled = cardIndex >= visibleCards - 1;
    document.querySelectorAll('.rr-row').forEach(function (row) {
      const on = row.dataset.brgy === brgyFilter && brgyFilter !== '';
      row.classList.toggle('active', on);
      row.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    byId('rrFilterPill').style.display = brgyFilter ? 'inline-flex' : 'none';
    byId('rrFilterText').textContent = brgyLabel ? 'Showing ' + brgyLabel : '';
    const cur = visibleCards ? matching[cardIndex] : null;
    byId('rrRequestsTitle').innerHTML = cur ? '<span class="rr-kind ' + (cur.classList.contains('is-pending') ? 'pending">Pending' : 'reviewed">Reviewed') + '</span>' : '';
    byId('rrRequestsTitle').appendChild(document.createTextNode((brgyLabel ? brgyLabel + ' · ' : '') + (visibleCards ? 'Request ' + (cardIndex + 1) : 'Requests')));
    byId('rrRequestsCount').textContent = visibleCards ? 'of ' + visibleCards : '(0)';
    if (caught) { byId('rrRequestsTitle').textContent = 'Pending requests'; byId('rrRequestsCount').textContent = '(0)'; }
    renderCalendar();
    renderList();
  }
  byId('rrBack').addEventListener('click', function () { browseReviewed = false; cardIndex = 0; applyFilters(); });
  byId('rrPrev').addEventListener('click', function () { if (cardIndex > 0) { cardIndex--; applyFilters(); } });
  byId('rrNext').addEventListener('click', function () { cardIndex++; applyFilters(); });

  // Clicking a barangay pops up ITS requests. The real cards are moved into the popup (forms and typed messages
  // keep working) and put back where they were when it closes.
  const brgyModal = byId('rrBrgyModal');
  let movedCards = [];
  function openBarangay(brgy, label) {
    closeBarangay(true);
    const cards = Array.prototype.slice.call(document.querySelectorAll('#requestList .request-card[data-brgy="' + brgy + '"]'));
    cards.forEach(function (card) {
      const marker = document.createComment('card');
      card.parentNode.insertBefore(marker, card);
      movedCards.push({ card: card, marker: marker });
      card.style.display = '';
      byId('rrBrgyBody').appendChild(card);
    });
    if (!cards.length) byId('rrBrgyBody').innerHTML = '<p class="storage-empty">No open requests for this barangay.</p>';
    byId('rrBrgyTitle').textContent = label + ' · ' + cards.length + ' request' + (cards.length === 1 ? '' : 's');
    document.querySelectorAll('.rr-row').forEach(function (row) {
      const on = row.dataset.brgy === brgy;
      row.classList.toggle('active', on);
      row.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    brgyModal.classList.add('open');
    byId('rrBrgyBody').scrollTop = 0;
    byId('rrBrgyClose').focus();
  }
  function closeBarangay(silent) {
    movedCards.forEach(function (m) { m.marker.parentNode.insertBefore(m.card, m.marker); m.marker.remove(); });
    movedCards = [];
    byId('rrBrgyBody').innerHTML = '';
    brgyModal.classList.remove('open');
    if (!silent) applyFilters(); // puts the single-card view and row highlight back
  }
  byId('rrBrgyClose').addEventListener('click', function () { closeBarangay(false); });
  brgyModal.addEventListener('click', function (event) { if (event.target === brgyModal) closeBarangay(false); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && brgyModal.classList.contains('open')) closeBarangay(false); });
  function selectBarangay(row) { openBarangay(row.dataset.brgy, row.dataset.label); }
  document.querySelectorAll('.rr-row').forEach(function (row) {
    row.addEventListener('click', function () { selectBarangay(row); });
    row.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectBarangay(row); }
    });
  });
  function clearBarangay() { brgyFilter = ''; brgyLabel = ''; selectedDate = ''; cardIndex = 0; applyFilters(); }
  byId('rrFilterClear').addEventListener('click', clearBarangay);

  // "Show all barangays" popup: every barangay's requests at once; clicking one jumps to it on the page.
  const allModal = byId('rrAllModal');
  const allSearch = byId('rrAllSearch');
  function openAll() {
    allSearch.value = '';
    filterAll();
    document.querySelectorAll('.rr-all-group').forEach(function (g) { g.classList.toggle('current', g.dataset.brgy === brgyFilter && brgyFilter !== ''); });
    allModal.classList.add('open');
    byId('rrAllBody').scrollTop = 0;
    byId('rrAllClose').focus();
  }
  function closeAll() { allModal.classList.remove('open'); byId('rrShowAll').focus(); }
  function filterAll() {
    const term = allSearch.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('.rr-all-group').forEach(function (g) {
      const ok = !term || g.dataset.search.includes(term);
      g.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    byId('rrAllNone').style.display = shown || !document.querySelector('.rr-all-group') ? 'none' : 'block';
  }
  byId('rrShowAll').addEventListener('click', openAll);
  byId('rrAllClose').addEventListener('click', closeAll);
  byId('rrAllCancel').addEventListener('click', closeAll);
  byId('rrAllReset').addEventListener('click', function () { allModal.classList.remove('open'); clearBarangay(); });
  allSearch.addEventListener('input', filterAll);
  allModal.addEventListener('click', function (event) { if (event.target === allModal) closeAll(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && allModal.classList.contains('open')) closeAll(); });
  document.querySelectorAll('.rr-all-group-head').forEach(function (head) {
    head.addEventListener('click', function () {
      allModal.classList.remove('open');
      const row = document.querySelector('.rr-row[data-brgy="' + head.dataset.brgy + '"]');
      if (!row) return;
      row.scrollIntoView({ block: 'nearest' });
      selectBarangay(row);
    });
  });
  byId('rrSearch').addEventListener('input', function (event) { searchTerm = event.target.value.trim().toLowerCase(); selectedDate = ''; cardIndex = 0; applyFilters(); });
  byId('storageShowAll').addEventListener('click', function () { selectedDate = ''; renderCalendar(); renderList(); });
  // A stored request opens in its own popup on top of the list: details, item table and Print request.
  const reqModal = byId('rrReqModal');
  function openReq(id) {
    const r = storageRequests.find(function (x) { return x.id === id; });
    if (!r) return;
    byId('rrReqTitle').textContent = r.request_type + ' - ' + r.barangay;
    byId('rrReqSub').textContent = 'Request #' + r.id + ' \u00b7 sent ' + r.requested;
    byId('rrReqBody').innerHTML =
      '<div class="rq-detail-meta"><div><span>Status</span>' + statusPill(r.status) + '</div><div><span>Requested by</span>' + escapeHtml(r.fullname) + '</div><div><span>Reviewed</span>' + escapeHtml(r.reviewed) + '</div></div>'
      + '<p class="rq-detail-reason"><strong>Reason:</strong> ' + escapeHtml(r.reason) + '</p>'
      + (r.note ? '<p class="rq-detail-reason"><strong>DRRM Office note:</strong> ' + escapeHtml(r.note) + '</p>' : '')
      + (r.delivery ? '<p class="rq-detail-reason"><strong>🚚 </strong>' + escapeHtml(r.delivery) + ' (' + escapeHtml(r.delivery_status) + ')</p>' : '')
      + (r.received ? '<p class="rq-detail-reason" style="color:#067647;"><strong>✅ Received:</strong> ' + escapeHtml(r.received) + '</p>' : '')
      + (r.proof ? '<a class="dlv-proof" href="' + r.proof + '" target="_blank" rel="noopener"><img src="' + r.proof + '" alt="Proof of delivery"><span>📷 Proof of delivery<br><small>click to enlarge</small></span></a>' : '')
      + '<div class="rr-items-head"><strong>Items requested</strong><a class="rr-print" href="request_print.php?id=' + r.id + '" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print request</a></div>' + r.items_html;
    reqModal.classList.add('open'); byId('rrReqBody').scrollTop = 0; byId('rrReqClose').focus();
  }
  function closeReq() { reqModal.classList.remove('open'); }
  byId('dayRequests').addEventListener('click', function (event) { const b = event.target.closest('.storage-pick'); if (b) openReq(+b.dataset.req); });
  byId('rrReqClose').addEventListener('click', closeReq);
  byId('rrReqBack').addEventListener('click', closeReq);
  reqModal.addEventListener('click', function (event) { if (event.target === reqModal) closeReq(); });
  // Request Storage list opens in a popup (from the button, or by clicking a day in the calendar).
  const storeModal = byId('rrStoreModal');
  function openStore() { storeModal.classList.add('open'); byId('dayRequests').scrollTop = 0; byId('rrStoreClose').focus(); }
  function closeStore() { storeModal.classList.remove('open'); selectedDate = ''; renderCalendar(); renderList(); byId('storageOpen').focus(); }
  byId('storageOpen').addEventListener('click', function () { selectedDate = ''; renderCalendar(); renderList(); openStore(); });
  byId('rrStoreClose').addEventListener('click', closeStore);
  storeModal.addEventListener('click', function (event) { if (event.target === storeModal) closeStore(); });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (reqModal.classList.contains('open')) { closeReq(); return; } // close the top popup first
    if (storeModal.classList.contains('open')) closeStore();
  });
  function stepCalendar(direction) {
    if (calView === 'years') yearPageStart += 12 * direction;
    else if (calView === 'months') calendarDate = new Date(calendarDate.getFullYear() + direction, calendarDate.getMonth(), 1);
    else calendarDate = new Date(calendarDate.getFullYear(), calendarDate.getMonth() + direction, 1);
    renderCalendar();
  }
  byId('previousMonth').addEventListener('click', function () { stepCalendar(-1); });
  byId('nextMonth').addEventListener('click', function () { stepCalendar(1); });
  byId('calendarTitle').addEventListener('click', function () { // days -> months -> years
    if (calView === 'days') calView = 'months';
    else if (calView === 'months') { calView = 'years'; yearPageStart = calendarDate.getFullYear() - (calendarDate.getFullYear() % 12); }
    renderCalendar();
  });
  byId('calendarGrid').addEventListener('click', function (event) { // tiles in the months / years views
    const tile = event.target.closest ? event.target.closest('.calendar-tile') : null;
    if (!tile) return;
    if (calView === 'years') { calendarDate = new Date(+tile.dataset.year, calendarDate.getMonth(), 1); calView = 'months'; }
    else if (calView === 'months') { calendarDate = new Date(calendarDate.getFullYear(), +tile.dataset.month, 1); calView = 'days'; }
    renderCalendar();
  });
  applyFilters();
</script>
<script>
// Delivery / pick-up switch: pick-up shows the time window and swaps the place; a pick-up the barangay did not ask for needs a reason.
function dlvSync(box) {
  var pick = box.querySelector('input[name="fulfillment"]:checked'), pk = pick && pick.value === 'pickup';
  box.classList.toggle('is-pickup', pk);
  box.classList.toggle('needs-reason', pk && box.dataset.pref !== 'pickup');
  var loc = box.querySelector('input[name="delivery_location"]');
  if (loc && (loc.value === box.dataset.deliverTo || loc.value === box.dataset.pickupAt || !loc.value.trim())) loc.value = pk ? box.dataset.pickupAt : box.dataset.deliverTo;
  var r = box.querySelector('select[name="method_reason"]'), o = box.querySelector('input[name="method_reason_other"]');
  if (o) o.style.display = r && r.value === 'other' ? '' : 'none';
}
document.querySelectorAll('.dlv-method').forEach(function (box) {
  box.addEventListener('change', function () { dlvSync(box); });
  var pick = box.querySelector('input[name="fulfillment"]:checked'), pk = pick && pick.value === 'pickup';
  box.classList.toggle('is-pickup', pk); box.classList.toggle('needs-reason', pk && box.dataset.pref !== 'pickup');
  var r = box.querySelector('select[name="method_reason"]'), o = box.querySelector('input[name="method_reason_other"]'); if (o) o.style.display = r && r.value === 'other' ? '' : 'none';
});
// Approving (or saving a schedule) needs the date and place, and the reason for an unrequested pick-up.
document.addEventListener('click', function (e) {
  var a = e.target.closest('button[value="approved"], button[value="schedule"]'); if (!a) return;
  var form = a.closest('form'); if (!form || !form.delivery_date) return;
  var box = form.querySelector('.dlv-method'), rs = form.querySelector('select[name="method_reason"]'), ro = form.querySelector('input[name="method_reason_other"]');
  var needReason = box && box.classList.contains('needs-reason') && (!rs.value || (rs.value === 'other' && !ro.value.trim()));
  var miss = !form.delivery_date.value ? form.delivery_date : (!form.delivery_location.value.trim() ? form.delivery_location : (needReason ? (rs.value === 'other' ? ro : rs) : null));
  if (miss) { e.preventDefault(); miss.focus(); miss.style.borderColor = '#d92d20'; alert(needReason ? 'Give the reason why the barangay has to pick up the items instead of delivery.' : 'Set the date and place first.'); }
});
// Declining a request needs a reason (the barangay is told why); approving does not.
document.addEventListener('click', function (e) {
  var b = e.target.closest('button[value="rejected"]'); if (!b) return;
  var form = b.closest('form'), note = form && form.querySelector('.review-note'); if (!note) return;
  if (!note.value.trim()) { e.preventDefault(); note.focus(); note.style.borderColor = '#d92d20'; note.style.boxShadow = '0 0 0 3px rgba(217,45,32,.18)'; note.placeholder = 'Type the reason for declining here (required)'; alert('Please enter the reason for declining so the barangay knows why.'); }
});
</script>
</body>
</html>
