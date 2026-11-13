<?php
require_once 'init.php';
require_login();
require_role(['admin']); // DRRMC Admin only
$training_notice = '';
// Message from the last action (shown once after the redirect below).
if (!empty($_SESSION['training_flash'])) { $training_notice = $_SESSION['training_flash']; unset($_SESSION['training_flash']); }
$training_error = '';
$training_admin_id = (int)$_SESSION['user_id'];
require_once __DIR__ . '/south_caloocan_map.php';
require_once __DIR__ . '/training_time.php';

// Which barangays a drill is for: 'all', or a comma list of barangay numbers ('1,2,22').
$drill_col = $conn->query("SHOW COLUMNS FROM training_drills LIKE 'barangays'");
if ($drill_col && $drill_col->num_rows === 0) $conn->query('ALTER TABLE training_drills ADD barangays VARCHAR(1000) NULL DEFAULT NULL');
$drill_districts = south_caloocan_districts();
$drill_all_numbers = [];
foreach ($drill_districts as $dd) foreach ($dd['barangays'] as $dn) $drill_all_numbers[(int)$dn] = true;
ksort($drill_all_numbers);

function drill_barangays_text($value) {
  $value = trim((string)$value);
  if ($value === '') return 'Not specified';
  if ($value === 'all') return 'All South Caloocan barangays';
  $nums = array_filter(array_map('intval', explode(',', $value)));
  if (count($nums) > 12) return count($nums) . ' barangays (' . implode(', ', array_map(function ($n) { return 'Brgy ' . $n; }, array_slice($nums, 0, 12))) . ', ...)';
  return implode(', ', array_map('barangay_label', $nums));
}

// Sends each barangay staff of the chosen barangays a message about the drill. Returns how many accounts were notified.
function drill_notify($barangays, $drill, $admin_id) {
  global $conn;
  if ($barangays === 'all') {
    $res = $conn->query("SELECT id, barangay FROM users WHERE role = 'user' AND status = 'approved'");
  } else {
    $nums = array_filter(array_map('intval', explode(',', $barangays)));
    if (!$nums) return 0;
    $res = $conn->query("SELECT id, barangay FROM users WHERE role = 'user' AND status = 'approved' AND barangay IN ('" . implode("','", $nums) . "')");
  }
  if (!$res) return 0;
  $ins = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'admin', ?)");
  $sent = 0;
  while ($u = $res->fetch_assoc()) {
    $text = '📢 New drill scheduled for ' . barangay_label($u['barangay']) . "\n\n"
      . 'Training / drill: ' . $drill['name'] . "\n"
      . 'Date and time: ' . date('l, F j, Y g:i A', strtotime($drill['at'])) . "\n"
      . 'Location: ' . $drill['location'] . "\n"
      . 'Participants: ' . $drill['participants'] . "\n"
      . 'Duration: ' . $drill['duration'] . "\n\n"
      . 'Scope and instructions:' . "\n" . $drill['scope'] . "\n\n"
      . 'You can also see this under Training & Drills. Please prepare your team. Thank you!' . "\n" . '- DRRM Office';
    $uid = (int)$u['id'];
    $ins->bind_param('iis', $uid, $admin_id, $text);
    if ($ins->execute()) $sent++;
  }
  $ins->close();
  return $sent;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_training_drill'])) {
  $training_name = trim((string)($_POST['training_name'] ?? ''));
  $scheduled_at = trim((string)($_POST['scheduled_at'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $scope = trim((string)($_POST['scope'] ?? ''));
  [$drill_time, $time_error] = training_time_from_post(true);
  $participants = $drill_time['participants'] ?? '';
  $duration = $drill_time['duration'] ?? '';
  $drill_end_at = $drill_time['end_at'] ?? null;
  $schedule_date = $drill_time ? DateTime::createFromFormat('Y-m-d H:i:s', $drill_time['scheduled_at']) : false;
  // Barangays: every South Caloocan barangay, or the ones ticked in the list.
  if (!empty($_POST['drill_all'])) {
    $drill_brgys = 'all';
  } else {
    $picked = array_unique(array_filter(array_map('intval', (array)($_POST['drill_barangays'] ?? [])), function ($n) use ($drill_all_numbers) { return isset($drill_all_numbers[$n]); }));
    sort($picked);
    $drill_brgys = implode(',', $picked);
  }
  if ($time_error) {
    $training_error = $time_error;
  } elseif ($training_name === '' || !$schedule_date || $location === '' || $scope === '' || $participants === '' || $duration === '') {
    $training_error = 'Complete every field before creating a scheduled drill.';
  } elseif ($drill_brgys === '') {
    $training_error = 'Choose at least one barangay for this drill.';
  } elseif ((function () use ($conn, $training_name, $schedule_date, $drill_brgys) {
      $at = $schedule_date->format('Y-m-d H:i:s');
      $st = $conn->prepare("SELECT id FROM training_drills WHERE training_name = ? AND scheduled_at = ? AND barangays = ? AND status <> 'cancelled' LIMIT 1");
      $st->bind_param('sss', $training_name, $at, $drill_brgys); $st->execute(); $dup = (bool)$st->get_result()->fetch_row(); $st->close(); return $dup; })()) {
    $training_error = 'This drill is already scheduled for the same date, time and barangays. No new message was sent.';
  } elseif ($clash = training_conflict($conn, $schedule_date->format('Y-m-d H:i:s'), $drill_end_at, $location, $drill_brgys)) {
    $training_error = training_conflict_message($clash);
  } else {
    $schedule_stmt = $conn->prepare('INSERT INTO training_drills (training_name, scheduled_at, location, scope, participants, duration, created_by, barangays, end_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $scheduled_at_sql = $schedule_date->format('Y-m-d H:i:s');
    $schedule_stmt->bind_param('ssssssiss', $training_name, $scheduled_at_sql, $location, $scope, $participants, $duration, $training_admin_id, $drill_brgys, $drill_end_at);
    $drill_saved = $schedule_stmt->execute();
    $notified = 0;
    if ($drill_saved) {
      $notified = drill_notify($drill_brgys, ['name' => $training_name, 'at' => $scheduled_at_sql, 'location' => $location, 'participants' => $participants, 'duration' => $duration, 'scope' => $scope], $training_admin_id);
      audit_log('TRAINING_DRILL_CREATED', $training_name, $location . ' @ ' . $scheduled_at_sql . '; barangays: ' . drill_barangays_text($drill_brgys) . '; ' . $notified . ' staff notified');
    }
    $training_notice = $drill_saved ? 'Drill scheduled for ' . drill_barangays_text($drill_brgys) . '. ' . $notified . ' barangay staff account' . ($notified === 1 ? ' was' : 's were') . ' notified in Messages.' : 'Unable to create the scheduled drill.';
    $schedule_stmt->close();
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_training_request'])) {
  $training_request_id = (int)($_POST['training_request_id'] ?? 0);
  $training_status = ($_POST['training_status'] ?? '') === 'approved' ? 'approved' : 'rejected';
  $training_message = trim((string)($_POST['training_message'] ?? ''));
  // The schedule is the one the barangay asked for: date, time and participants are fixed, not taken from the form.
  $fx = $conn->prepare('SELECT requested_date, start_time, end_time, participants FROM training_requests WHERE id = ? LIMIT 1');
  $fx->bind_param('i', $training_request_id); $fx->execute(); $fixed = $fx->get_result()->fetch_assoc(); $fx->close();
  if ($fixed && !empty($fixed['start_time']) && !empty($fixed['end_time']) && (int)$fixed['participants'] > 0) {
    $_POST['drill_date'] = $fixed['requested_date'];
    $_POST['start_time'] = substr($fixed['start_time'], 0, 5);
    $_POST['end_time'] = substr($fixed['end_time'], 0, 5);
    $_POST['participants'] = (int)$fixed['participants'];
  }
  [$drill_time, $time_error] = training_time_from_post(false);
  $participants = $drill_time['participants'] ?? 'To be confirmed';
  $duration = $drill_time['duration'] ?? 'To be confirmed';
  $drill_end_at = $drill_time['end_at'] ?? null;
  $schedule_date = $drill_time ? DateTime::createFromFormat('Y-m-d H:i:s', $drill_time['scheduled_at']) : false;
  if ($training_status === 'approved' && $time_error) {
    $training_error = $time_error;
  } elseif ($training_status === 'rejected' && $training_message === '') {
    $training_error = 'Enter the reason for declining so the barangay knows why.';
  } elseif ($training_request_id < 1 || ($training_status === 'approved' && !$schedule_date)) {
    $training_error = 'Enter a reply, and the date, time and participants when approving a request.';
  } else {
    $request_stmt = $conn->prepare("SELECT tr.*, u.fullname, u.barangay FROM training_requests tr INNER JOIN users u ON u.id=tr.user_id WHERE tr.id=? AND tr.status='pending' LIMIT 1");
    $request_stmt->bind_param('i', $training_request_id);
    $request_stmt->execute();
    $training_request = $request_stmt->get_result()->fetch_assoc();
    $request_stmt->close();
    $clash = ($training_request && $training_status === 'approved') ? training_conflict($conn, $drill_time['scheduled_at'], $drill_end_at, $training_request['location'], ctype_digit((string)$training_request['barangay']) ? (string)(int)$training_request['barangay'] : '') : null;
    if (!$training_request) $training_error = 'This training request was already handled or is unavailable.';
    elseif ($clash) $training_error = training_conflict_message($clash);
    else {
      // Automatic message to the barangay, written from the decision.
      $full_message = $training_status === 'approved'
        ? '✅ Your training request "' . $training_request['training_name'] . '" has been APPROVED and scheduled.' . "\n\n"
          . 'Date and time: ' . date('l, F j, Y g:i A', strtotime($drill_time['scheduled_at'])) . ' - ' . date('g:i A', strtotime($drill_time['end_at'])) . "\n"
          . 'Location: ' . $training_request['location'] . "\nParticipants: " . $participants . "\nDuration: " . $duration
          . ($training_message !== '' ? "\n\nNote: " . $training_message : '') . "\n\nYou can see it under Training & Drills. - DRRM Office"
        : '❌ Your training request "' . $training_request['training_name'] . '" was DECLINED by the DRRM Office.' . "\n\nReason: " . $training_message . "\n\n- DRRM Office";
      $conn->begin_transaction();
      try {
        $scheduled_at_sql = $schedule_date ? $schedule_date->format('Y-m-d H:i:s') : null;
        $update_stmt = $conn->prepare("UPDATE training_requests SET status=?, admin_reply=?, scheduled_at=?, reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status='pending'");
        $update_stmt->bind_param('sssii', $training_status, $training_message, $scheduled_at_sql, $training_admin_id, $training_request_id);
        $update_stmt->execute();
        if ($update_stmt->affected_rows !== 1) throw new RuntimeException('This training request was already handled.');
        $update_stmt->close();
        $message_stmt = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'admin', ?)");
        $message_stmt->bind_param('iis', $training_request['user_id'], $training_admin_id, $full_message);
        if (!$message_stmt->execute()) throw new RuntimeException('Unable to save the acknowledgement message.');
        $message_stmt->close();
        if ($training_status === 'approved') {
          $drill_stmt = $conn->prepare('INSERT INTO training_drills (training_name, scheduled_at, location, scope, participants, duration, request_id, created_by, end_at, barangays) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . (ctype_digit((string)$training_request['barangay']) ? (int)$training_request['barangay'] : 'NULL') . ')');
          $drill_stmt->bind_param('ssssssiis', $training_request['training_name'], $scheduled_at_sql, $training_request['location'], $training_request['details'], $participants, $duration, $training_request_id, $training_admin_id, $drill_end_at);
          if (!$drill_stmt->execute()) throw new RuntimeException('Unable to create the scheduled sheet.');
          $drill_stmt->close();
        }
        $conn->commit();
        audit_log('TRAINING_REQUEST_' . strtoupper($training_status), 'request #' . $training_request_id, $training_request['training_name']);
        $training_notice = $training_status === 'approved' ? 'Request approved and added to the scheduled training sheets.' : 'Request declined and reply sent to the barangay user.';
      } catch (Throwable $exception) {
        $conn->rollback();
        $training_error = $exception->getMessage();
      }
    }
  }
}

// Cancel a scheduled drill: a reason is required, and every staff account of its barangays is told in Messages.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_training_drill'])) {
  $cancel_id = (int)($_POST['drill_id'] ?? 0);
  $cancel_reason = trim((string)($_POST['cancel_reason'] ?? ''));
  $cst = $conn->prepare("SELECT * FROM training_drills WHERE id = ? AND status = 'scheduled' LIMIT 1");
  $cst->bind_param('i', $cancel_id); $cst->execute(); $cancel_drill = $cst->get_result()->fetch_assoc(); $cst->close();
  if (!csrf_valid($_POST['csrf_token'] ?? '')) {
    $training_error = 'Your session expired. Reload the page and try again.';
  } elseif (!$cancel_drill) {
    $training_error = 'This drill was already cancelled or finished.';
  } elseif ($cancel_reason === '') {
    $training_error = 'Enter the reason for cancelling so the barangays know why.';
  } else {
    $up = $conn->prepare("UPDATE training_drills SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'");
    $up->bind_param('i', $cancel_id); $up->execute(); $ok = $up->affected_rows === 1; $up->close();
    $sent = 0;
    if ($ok) {
      $brgys = (string)($cancel_drill['barangays'] ?? '');
      $nums = $brgys === 'all' ? null : array_filter(array_map('intval', explode(',', $brgys)));
      $res = $nums === null ? $conn->query("SELECT id FROM users WHERE role = 'user' AND status = 'approved'")
        : ($nums ? $conn->query("SELECT id FROM users WHERE role = 'user' AND status = 'approved' AND barangay IN ('" . implode("','", $nums) . "')") : false);
      $text = '❌ Scheduled drill CANCELLED' . "\n\n" . 'Training / drill: ' . $cancel_drill['training_name'] . "\n"
        . 'Was scheduled: ' . training_when_text($cancel_drill) . "\n" . 'Location: ' . $cancel_drill['location'] . "\n\n"
        . 'Reason: ' . $cancel_reason . "\n\n" . '- DRRM Office';
      if ($res) {
        $ins = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'admin', ?)");
        while ($u = $res->fetch_assoc()) { $uid = (int)$u['id']; $ins->bind_param('iis', $uid, $training_admin_id, $text); if ($ins->execute()) $sent++; }
        $ins->close();
      }
      audit_log('TRAINING_DRILL_CANCELLED', $cancel_drill['training_name'], training_when_text($cancel_drill) . '; reason: ' . $cancel_reason . '; ' . $sent . ' staff notified');
      $_SESSION['training_flash'] = 'Drill cancelled. ' . $sent . ' barangay staff account' . ($sent === 1 ? ' was' : 's were') . ' notified in Messages.';
      header('Location: admin_training.php?tab=sheets');
      exit;
    }
    $training_error = 'Unable to cancel this drill.';
  }
}

$training_requests = [];
$training_requests_result = $conn->query("SELECT tr.*, u.fullname, u.barangay, u.position FROM training_requests tr INNER JOIN users u ON u.id=tr.user_id WHERE tr.status='pending' ORDER BY tr.created_at DESC");
if ($training_requests_result) while ($training_request = $training_requests_result->fetch_assoc()) $training_requests[] = $training_request;
$handled_training_requests = [];
$handled_training_result = $conn->query("SELECT tr.*, u.fullname, u.barangay, u.position FROM training_requests tr INNER JOIN users u ON u.id=tr.user_id WHERE tr.status IN ('approved','rejected') ORDER BY tr.reviewed_at DESC, tr.id DESC");
if ($handled_training_result) while ($handled_training_request = $handled_training_result->fetch_assoc()) $handled_training_requests[] = $handled_training_request;
$training_drills = [];
$training_drills_result = $conn->query("SELECT td.*, u.fullname AS creator_name FROM training_drills td INNER JOIN users u ON u.id=td.created_by WHERE td.status <> 'cancelled' ORDER BY td.scheduled_at ASC, td.id ASC");
if ($training_drills_result) while ($training_drill = $training_drills_result->fetch_assoc()) $training_drills[] = $training_drill;

// Which tab opens first: stay on the form that was just submitted (or jump to the sheets after a new drill is saved).
// After a successful save, redirect (post/redirect/get): refreshing the page can no longer create the drill
// again or send the barangays the same message twice.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$training_error && $training_notice !== '' && (isset($_POST['create_training_drill']) || isset($_POST['respond_training_request']))) {
  $_SESSION['training_flash'] = $training_notice;
  header('Location: admin_training.php?tab=' . (isset($_POST['respond_training_request']) ? 'requests' : 'sheets'));
  exit;
}
$training_tab = 'schedule';
if (isset($_POST['respond_training_request'])) $training_tab = 'requests';
elseif (isset($_POST['create_training_drill']) && !$training_error) $training_tab = 'sheets';
elseif (($_GET['tab'] ?? '') === 'requests' || ($_GET['tab'] ?? '') === 'sheets') $training_tab = $_GET['tab'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Training & Drills</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .drill-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:16px; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .drill-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
    .drill-header h3 { margin:0; color:#1f2937; font-size:16px; }
    .drill-type { display:inline-block; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:700; background:#f3f4f6; color:#374151; }
    .drill-date { font-size:12px; color:#6b7280; }
    .drill-body { color:#6b7280; font-size:14px; line-height:1.6; }
    .drill-stats { display:flex; gap:24px; margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb; }
    .stat-item { font-size:12px; }
    .stat-label { color:#9ca3af; display:block; margin-bottom:2px; }
    .stat-value { color:#1f2937; font-weight:700; font-size:14px; }
    .btn-small { padding:8px 12px; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:#3b82f6; color:#fff; }
    .training-acknowledgement { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .training-acknowledgement h3 { margin:0 0 5px; color:#1f2937; font-size:16px; }
    .training-acknowledgement p { margin:0 0 12px; color:#6b7280; font-size:13px; }
    .training-acknowledgement form { display:grid; gap:9px; }
    .training-acknowledgement select, .training-acknowledgement textarea { width:100%; padding:9px 10px; border:1px solid #d9e1dc; border-radius:5px; font:inherit; }
    .training-acknowledgement textarea { min-height:62px; resize:vertical; }
    .training-acknowledgement button { justify-self:end; }
    .training-request-row { margin-top:12px; padding:14px; border:1px solid #e5e7eb; border-left:4px solid #f59e0b; border-radius:6px; background:#fffbeb; }
    .training-request-row > strong, .training-request-row > span, .training-request-row > small { display:block; margin-bottom:5px; }
    .training-request-row > strong { color:#1f2937; }
    .training-request-row > span, .training-request-row > small { color:#667085; font-size:12px; }
    .training-request-row form { margin-top:10px; }
    .training-request-row form textarea { margin-top:5px; }
    .training-approved, .training-empty { color:#667085; font-size:12px; }
    .training-storage-button { border:0; border-radius:6px; padding:9px 13px; background:#2d7a5c; color:#fff; font-weight:700; cursor:pointer; }
    .training-storage-modal { display:none; position:fixed; inset:0; z-index:20; padding:7vh 18px; background:rgba(16,24,40,.5); }
    .training-storage-modal.is-open { display:block; }
    .training-storage-dialog { max-width:760px; max-height:85vh; overflow-y:auto; margin:0 auto; background:#fff; border-radius:12px; padding:22px; box-shadow:0 16px 40px rgba(16,24,40,.2); }
    .training-storage-header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
    .training-storage-header h3 { margin:0; color:#1f2937; }
    .training-storage-close { border:0; background:transparent; color:#667085; font-size:22px; cursor:pointer; }
    .training-storage-item { border-top:1px solid #eaecf0; padding:14px 0; color:#667085; font-size:13px; line-height:1.6; }
    .training-storage-item strong { color:#1f2937; }
    .schedule-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
    .schedule-form label { display:grid; gap:5px; color:#344054; font-size:12px; font-weight:600; }
    .schedule-form input, .schedule-form textarea { width:100%; box-sizing:border-box; padding:9px; border:1px solid #d0d5dd; border-radius:5px; font:inherit; }
    .schedule-form textarea { min-height:72px; resize:vertical; }
    .schedule-form .wide { grid-column:1 / -1; }
    .schedule-form button { justify-self:start; }
    .schedule-sheet { background:#fff; border:1px solid #e5e7eb; border-left:4px solid #2d7a5c; border-radius:8px; padding:18px; margin-bottom:14px; box-shadow:0 2px 8px rgba(16,24,40,.05); }
    .schedule-sheet header { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin:0 0 10px; }
    .schedule-sheet h3 { margin:0; color:#1f2937; font-size:16px; }
    .schedule-sheet header span { color:#2d7a5c; font-size:12px; font-weight:700; white-space:nowrap; }
    .schedule-sheet p { margin:6px 0; color:#667085; font-size:13px; line-height:1.5; }
    /* Three section buttons side by side; each shows its own panel. */
    .training-tabs { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:0; margin-bottom:16px; padding:6px; background:rgba(255,255,255,.9); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
    .training-tab { display:flex; align-items:center; justify-content:center; gap:8px; padding:14px 12px; border:0; border-radius:10px; background:transparent; color:#344054; font:inherit; font-size:14px; font-weight:700; cursor:pointer; transition:background .15s, color .15s; }
    .training-tab:hover { background:#fff7ed; color:#9a4a00; }
    .training-tab:focus-visible { outline:2px solid #ff8c00; outline-offset:2px; }
    .training-tab.active { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; box-shadow:0 4px 12px rgba(234,122,0,.3); }
    .training-tab .tab-count { min-width:22px; padding:1px 7px; border-radius:999px; background:#f2f4f7; color:#475467; font-size:11px; }
    .training-tab.active .tab-count { background:rgba(255,255,255,.28); color:#fff; }
    .training-tab .tab-count.alert { background:#f59e0b; color:#fff; }
    .training-panel[hidden] { display:none !important; }
    .training-panel { background:rgba(255,255,255,.95); border:1px solid rgba(255,255,255,.7); border-radius:14px; padding:20px 22px; box-shadow:0 8px 24px rgba(120,60,0,.12); margin-bottom:20px; }
    .training-panel.training-acknowledgement { box-shadow:0 8px 24px rgba(120,60,0,.12); }
    /* Theme turns every "section h3" into a gradient bar; keep these as plain headings. */
    .layout .training-panel h3, .layout .schedule-sheet h3 { background:none; padding:0; border-radius:0; color:#1f2937; }
    .training-panel-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:4px; }
    .training-panel-head h3 { margin:0; font-size:17px; }
    .layout .training-storage-button { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); }
    .training-storage-button:hover { filter:brightness(1.08); }
    .schedule-sheet { border-left-color:#ff8c00; }
    .schedule-sheet header span { color:#c56a00; }
    .training-request-row { border-left-color:#ff8c00; background:#fff7ed; }
    .schedule-form input:focus, .schedule-form textarea:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    @media (max-width:640px) {
      .training-tabs { grid-template-columns:1fr; }
      .schedule-form { grid-template-columns:1fr; }
    }
    .training-page-shell { flex:1; min-height:0; height:100vh; }
    .training-scroll { min-height:0; overflow-y:auto; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    .training-scroll::-webkit-scrollbar { width:10px; }
    .training-scroll::-webkit-scrollbar-track { background:#eef1f4; }
    .training-scroll::-webkit-scrollbar-thumb { background:#9aa7b5; border:2px solid #eef1f4; border-radius:5px; }
    .training-scroll::-webkit-scrollbar-thumb:hover { background:#687585; }
    @media (max-width:1200px) {
      .training-page-shell { height:auto; min-height:0; }
      .training-scroll { overflow-y:visible; }
    }
      .drill-brgy { display:grid; gap:10px; padding:14px; border:1px solid #f0e6da; border-radius:12px; background:#fffaf4; }
    .drill-brgy-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; font-size:13px; font-weight:700; color:#344054; text-transform:uppercase; letter-spacing:.4px; }
    .drill-brgy-head small { text-transform:none; letter-spacing:0; font-weight:400; color:#667085; }
    .drill-brgy-head b { text-transform:none; letter-spacing:0; color:#9a4a00; background:#fff1df; border-radius:999px; padding:3px 10px; font-size:12px; }
    .drill-brgy .drill-all { display:flex !important; align-items:center; gap:8px; font-size:14px; font-weight:700; color:#1f2937; text-transform:none; letter-spacing:0; cursor:pointer; }
    .drill-brgy input[type=checkbox] { width:16px; height:16px; margin:0; accent-color:#ea7a00; }
    .drill-brgy-tools { display:flex; flex-wrap:wrap; align-items:center; gap:6px; font-size:12px; color:#667085; }
    .drill-district { margin:0; padding:5px 10px; border:1px solid #f5c48a; border-radius:999px; background:#fff; color:#c56a00; font:inherit; font-size:12px; font-weight:700; cursor:pointer; width:auto; }
    .drill-district:hover { background:#fff1df; }
    .drill-clear { color:#667085; border-color:#d0d5dd; }
    .drill-brgy-tools input[type=search] { flex:1 1 180px; min-width:160px; width:auto; margin:0; padding:7px 10px; font-size:13px; }
    .drill-brgy-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(62px,1fr)); gap:5px; max-height:190px; overflow-y:auto; padding:2px; }
    .drill-brgy-grid.disabled { opacity:.45; pointer-events:none; }
    .drill-brgy .drill-chip { display:flex !important; align-items:center; gap:5px; padding:5px 7px; border:1px solid #e4e7ec; border-radius:8px; background:#fff; font-size:12px; font-weight:600; color:#344054; text-transform:none; letter-spacing:0; cursor:pointer; }
    .drill-brgy .drill-chip:has(input:checked) { border-color:#ea7a00; background:#fff1df; color:#9a4a00; }
    .drill-chip[hidden] { display:none !important; }
    .drill-place-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:8px; }
    .drill-brgy .drill-place-pick { display:grid !important; gap:5px; flex:1 1 280px; font-size:12px; font-weight:700; color:#475467; text-transform:uppercase; letter-spacing:.4px; }
    .drill-place-pick select { width:100%; margin:0; padding:10px 12px; border:1px solid #f5c48a; border-radius:10px; background:#fff; font:inherit; font-size:14px; text-transform:none; letter-spacing:0; font-weight:600; color:#1f2937; }
    .drill-place-row .drill-district { padding:10px 14px; }
    .drill-place-box { padding:12px; border:1px solid #f5c48a; border-radius:12px; background:#fff; }
    .drill-place-box.disabled { opacity:.45; pointer-events:none; }
    .drill-place-box[hidden] { display:none; }
    .drill-place-title { font-size:13px; font-weight:700; color:#9a4a00; margin-bottom:8px; }
    .drill-place-box .drill-brgy-grid { max-height:none; grid-template-columns:repeat(auto-fill, minmax(96px,1fr)); }
    .drill-picked { margin:0; font-size:13px; color:#667085; line-height:2; }
    .drill-picked b { color:#344054; margin-right:4px; }
    .drill-tag { display:inline-flex; align-items:center; gap:5px; margin:0 4px 0 0; padding:1px 4px 1px 10px; border-radius:999px; background:#fff1df; border:1px solid #f5c48a; color:#9a4a00; font-weight:700; font-size:12px; line-height:1.6; }
    .drill-tag i { font-style:normal; cursor:pointer; padding:0 5px; border-radius:50%; }
    .drill-tag i:hover { background:#f5c48a; }
    .drill-cal { margin:0 0 16px; padding:14px; border:1px solid #f0e6da; border-radius:14px; background:#fffaf4; }
    .drill-cal-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .drill-cal-head b { flex:1; text-align:center; font-size:18px; color:#1f2937; }
    .drill-cal-nav, .drill-cal-today { width:auto; margin:0; padding:6px 12px; border:1px solid #f5c48a; border-radius:8px; background:#fff; color:#c56a00; font:inherit; font-size:18px; font-weight:800; cursor:pointer; line-height:1; }
    .drill-cal-today { font-size:12px; padding:8px 12px; }
    .drill-cal-grid { display:grid; grid-template-columns:repeat(7, minmax(0,1fr)); gap:4px; }
    .drill-cal-dow { text-align:center; font-size:11px; font-weight:800; color:#9a4a00; text-transform:uppercase; padding:4px 0; }
    .drill-cal-day { min-height:74px; padding:5px; border:1px solid #f0e6da; border-radius:8px; background:#fff; font-size:12px; cursor:default; overflow:hidden; }
    .drill-cal-day.other { background:transparent; border-color:transparent; }
    .drill-cal-day.today { border-color:#ea7a00; box-shadow:inset 0 0 0 1px #ea7a00; }
    .drill-cal-day.has { cursor:pointer; }
    .drill-cal-day.has:hover, .drill-cal-day.sel { background:#fff1df; }
    .drill-cal-day.sel { border-color:#ea7a00; }
    .drill-cal-day > b { display:block; font-size:12px; color:#475467; margin-bottom:3px; }
    .drill-cal-ev { display:block; margin-top:2px; padding:2px 5px; border-radius:5px; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font-size:11px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .drill-cal-ev.done { background:#98a2b3; }
    .drill-cal-sum { margin:10px 0 0; font-size:13px; color:#475467; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .drill-cal-sum button { width:auto; margin:0 0 0 8px; padding:3px 10px; border:1px solid #d0d5dd; border-radius:999px; background:#fff; color:#475467; font:inherit; font-size:12px; cursor:pointer; }
    .schedule-sheet[hidden] { display:none !important; }
    .drill-cal { margin:0; display:flex; flex-direction:column; box-sizing:border-box; }
    .drill-cal-grid { flex:1; min-height:0; }
    .drill-cal-day { min-height:0 !important; display:flex; flex-direction:column; gap:3px; }
    .drill-cal-day > b { font-size:14px; }
    .drill-cal-ev { cursor:pointer; flex-shrink:0; margin-top:0 !important; padding:2px 8px !important; font-size:12px !important; line-height:16px; }
    .drill-cal-day > b { margin-bottom:1px !important; }
    .drill-cal-day { overflow:hidden; position:relative; }
    .drill-cal-ev[hidden] { display:none !important; }
    .drill-cal-day .drill-cal-ev.more { position:absolute; top:5px; right:6px; margin:0; padding:1px 8px !important; border-radius:999px; font-size:11px !important; }
    /* Scheduled Sheets: use the full width of the page so the calendar is big */
    div[style*="max-width:900px"]:has(#panel-sheets:not([hidden])) { max-width:none !important; }
    #panel-sheets .drill-cal { padding:12px 16px; }
    #panel-sheets .drill-cal-grid { gap:6px; }
    .drill-cal-ev:hover { filter:brightness(1.1); box-shadow:0 2px 6px rgba(0,0,0,.18); }
    .drill-cal-ev.more { background:#fff1df !important; color:#9a4a00 !important; }
    .drill-cal-day.has { cursor:pointer; }
    .drill-pop { position:fixed; inset:0; z-index:2000; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.5); }
    .drill-pop[hidden] { display:none; }
    .drill-cal-ev.completed, .drill-cal-ev.cancelled { background:#98a2b3 !important; }
    .drill-cal-ev.completed::before { content:'\2713  '; }
    .drill-cal-ev.cancelled { text-decoration:line-through; }
    .drill-cal-ev.ongoing { background:#d92d20 !important; animation:drillPulse 1.6s ease-in-out infinite; }
    .drill-cal-ev.ongoing::before { content:'\25CF  LIVE  '; }
    @keyframes drillPulse { 50% { box-shadow:0 0 0 4px rgba(217,45,32,.25); } }
    .drill-st { padding:3px 10px; border-radius:999px; font-size:11px; font-weight:800; }
    .drill-st.upcoming { background:#fff1df; color:#c56a00; } .drill-st.ongoing { background:#fee4e2; color:#b42318; }
    .drill-st.completed { background:#dcfae6; color:#067647; } .drill-st.cancelled { background:#f2f4f7; color:#475467; }
    .drill-cal-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:12px; color:#475467; margin-left:auto; }
    .drill-cal-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
    .drill-pop-box { width:min(620px, 100%); max-height:85vh; display:flex; flex-direction:column; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .drill-pop-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 20px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; }
    .drill-pop-head b { font-size:18px; }
    .drill-pop-x { width:auto; margin:0; padding:0 6px; border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; }
    .drill-pop-body { padding:16px 20px; overflow-y:auto; }
    .drill-pop-body .schedule-sheet { margin:0 0 12px; }
    .rq-fixed { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; }
    .rq-fixed > div { padding:10px 12px; border:1px solid #fde3c4; border-radius:10px; background:#fff; }
    .rq-fixed span { display:block; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#9a4a00; }
    .rq-fixed b { display:block; margin-top:2px; font-size:15px; color:#1f2937; }
    .rq-fixed small { display:block; font-size:11px; color:#9a4a00; }
    .rq-clash { margin-top:10px; padding:10px 12px; border-radius:8px; background:#fef3f2; border:1px solid #fda29b; color:#b42318; font-size:13px; line-height:1.5; }
    button[disabled].btn-small { opacity:.45; cursor:not-allowed; }
    @media (max-width:760px) { .rq-fixed { grid-template-columns:1fr 1fr; } }
    .drill-cancel { margin-top:10px; border-top:1px dashed #f0d4d1; padding-top:8px; }
    .drill-cancel summary { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border:1px solid #fda29b; border-radius:8px; color:#b42318; font-size:12.5px; font-weight:700; cursor:pointer; list-style:none; }
    .drill-cancel summary::-webkit-details-marker { display:none; }
    .drill-cancel summary:hover { background:#fef3f2; }
    .drill-cancel[open] summary { background:#fef3f2; }
    .drill-cancel-form { margin-top:10px; display:flex; flex-direction:column; gap:6px; }
    .drill-cancel-form label { font-size:12.5px; font-weight:700; color:#344054; } .drill-cancel-form label small { font-weight:400; color:#667085; }
    .drill-cancel-form textarea { width:100%; margin:0; padding:8px 10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:13px; resize:vertical; }
    .drill-cancel-form button { align-self:flex-start; border:0; border-radius:8px; padding:8px 14px; background:#d92d20; color:#fff; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .drill-cancel-form button:hover { background:#b42318; }
    .drill-pop-body .schedule-sheet:last-child { margin-bottom:0; }
</style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="training-page-shell" style="display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;"><h2 style="margin:0;font-size:24px;">Training & Drills</h2><p style="margin:4px 0 0;color:#666;font-size:13px;">Schedule, track, and evaluate disaster response training exercises.</p></header>
    <main class="main-content training-scroll" style="flex:1; padding:24px;">
      <div style="max-width:900px; margin:0 auto;">
        <?php if ($training_notice): ?><div class="notice"><?php echo e($training_notice); ?></div><?php endif; ?>
        <?php if ($training_error): ?><div class="errors"><?php echo e($training_error); ?></div><?php endif; ?>
        <div class="training-tabs" role="tablist" aria-label="Training sections">
          <button type="button" class="training-tab<?= $training_tab === 'schedule' ? ' active' : '' ?>" role="tab" id="tab-schedule" aria-controls="panel-schedule" aria-selected="<?= $training_tab === 'schedule' ? 'true' : 'false' ?>" data-tab="schedule">📅 Schedule New Drill</button>
          <button type="button" class="training-tab<?= $training_tab === 'requests' ? ' active' : '' ?>" role="tab" id="tab-requests" aria-controls="panel-requests" aria-selected="<?= $training_tab === 'requests' ? 'true' : 'false' ?>" data-tab="requests">📨 Barangay Drill Requests <span class="tab-count<?= $training_requests ? ' alert' : '' ?>"><?= count($training_requests) ?></span></button>
          <button type="button" class="training-tab<?= $training_tab === 'sheets' ? ' active' : '' ?>" role="tab" id="tab-sheets" aria-controls="panel-sheets" aria-selected="<?= $training_tab === 'sheets' ? 'true' : 'false' ?>" data-tab="sheets">📋 Scheduled Sheets <span class="tab-count"><?= count($training_drills) ?></span></button>
        </div>

        <section class="training-acknowledgement training-panel" role="tabpanel" id="panel-schedule" aria-labelledby="tab-schedule"<?= $training_tab === 'schedule' ? '' : ' hidden' ?>>
          <h3>Schedule New Drill</h3><p>Create a scheduled sheet that will be visible to the admin team.</p>
          <form method="post" class="schedule-form">
            <input type="hidden" name="create_training_drill" value="1">
            <label>Training or drill<input name="training_name" required placeholder="Flood response exercise"></label>
            <label>Location<input name="location" required placeholder="Barangay hall or training center"></label>
            <?= training_time_fields_html(['date' => $_POST['drill_date'] ?? '', 'start' => $_POST['start_time'] ?? '08:00', 'end' => $_POST['end_time'] ?? '17:00', 'participants' => $_POST['participants'] ?? 0], true, date('Y-m-d')) ?>
            <div class="wide drill-brgy">
              <div class="drill-brgy-head"><span>Barangays <small>(who will join this drill; their staff are notified in Messages)</small></span><b id="drillBrgyCount">None selected</b></div>
              <label class="drill-all"><input type="checkbox" name="drill_all" value="1" id="drillAll"> All South Caloocan barangays (<?= count($drill_all_numbers) ?>)</label>
              <div class="drill-place-row">
                <label class="drill-place-pick">Place
                  <select id="drillPlace">
                    <option value="">Choose a place...</option>
                    <?php foreach ($drill_districts as $dname => $dd): ?><option value="<?= e($dname) ?>"><?= e($dname) ?> (<?= count($dd['barangays']) ?> barangays)</option><?php endforeach; ?>
                  </select>
                </label>
                <button type="button" class="drill-district" id="drillPlaceAll" hidden>Select all in this place</button>
                <button type="button" class="drill-district drill-clear" id="drillClear">Clear all</button>
              </div>
              <div class="drill-place-box" id="drillPlaceBox" hidden>
                <div class="drill-place-title" id="drillPlaceTitle"></div>
                <div class="drill-brgy-grid" id="drillBrgyGrid">
                  <?php foreach ($drill_districts as $dname => $dd): foreach ($dd['barangays'] as $bn): ?><label class="drill-chip" data-place="<?= e($dname) ?>" data-n="<?= (int)$bn ?>" hidden><input type="checkbox" name="drill_barangays[]" value="<?= (int)$bn ?>"> Brgy <?= (int)$bn ?></label><?php endforeach; endforeach; ?>
                </div>
              </div>
              <p class="drill-picked" id="drillPicked">No barangay selected yet. Choose a place, then tick the barangays.</p>
            </div>
            <label class="wide">Scope and instructions<textarea name="scope" required placeholder="Exercise scope, objectives, and instructions"></textarea></label>
            <button type="submit" class="btn btn-accent">Create Scheduled Sheet</button>
          </form>
        </section>

        <section class="training-acknowledgement training-panel" role="tabpanel" id="panel-requests" aria-labelledby="tab-requests"<?= $training_tab === 'requests' ? '' : ' hidden' ?>><div class="training-panel-head"><h3>Barangay Drill Requests</h3><button class="training-storage-button" id="openTrainingStorage" type="button"><i class="fa-solid fa-box-archive"></i> Request Storage (<?= count($handled_training_requests) ?>)</button></div><p>Review the requestor and request, then reply. Approving creates a scheduled sheet and messages the requestor.</p>
          <?php if (!$training_requests): ?><p class="training-empty">No pending training requests.</p><?php else: foreach ($training_requests as $training_request): ?>
            <div class="training-request-row"><strong><?= e($training_request['training_name']) ?> - <?= e($training_request['fullname']) ?></strong>
              <span><?= e(barangay_label($training_request['barangay'])) ?> · <?= e($training_request['position']) ?> · Preferred <?= e(date('F j, Y', strtotime($training_request['requested_date']))) ?></span>
              <small><strong>Location:</strong> <?= e($training_request['location']) ?><br><strong>Request:</strong> <?= e($training_request['details']) ?></small>
              <form method="post" class="schedule-form">
                <input type="hidden" name="respond_training_request" value="1"><input type="hidden" name="training_request_id" value="<?= (int)$training_request['id'] ?>">
                <label>Note / reason <span style="font-weight:400;color:#667085;text-transform:none;letter-spacing:0;">(optional when approving, <b style="color:#b42318;">required when declining</b>; the barangay is messaged automatically)</span><textarea name="training_message" class="review-note" placeholder="e.g. Please prepare the venue / The date is already fully booked"></textarea></label>
                <?php $rq_fixed = !empty($training_request['start_time']) && !empty($training_request['end_time']) && (int)$training_request['participants'] > 0; $rq_clash = null; ?>
                <?php if ($rq_fixed): $rq_s = substr($training_request['start_time'], 0, 5); $rq_e = substr($training_request['end_time'], 0, 5);
                  $rq_clash = training_conflict($conn, $training_request['requested_date'] . ' ' . $rq_s . ':00', $training_request['requested_date'] . ' ' . $rq_e . ':00', $training_request['location'], ctype_digit((string)$training_request['barangay']) ? (string)(int)$training_request['barangay'] : ''); ?>
                  <div class="wide">
                    <div style="font-size:13px;font-weight:700;color:#344054;margin-bottom:6px;">Schedule requested by the barangay <small style="font-weight:400;color:#667085;">(fixed; approving books exactly this)</small></div>
                    <div class="rq-fixed">
                      <div><span>Date</span><b><?= e(date('D, M j, Y', strtotime($training_request['requested_date']))) ?></b></div>
                      <div><span>Time</span><b><?= e(date('g:i A', strtotime($rq_s)) . ' - ' . date('g:i A', strtotime($rq_e))) ?></b></div>
                      <div><span>Participants</span><b><?= (int)$training_request['participants'] ?></b></div>
                      <div><span>Duration</span><b><?php $rq_h = training_hours($rq_s, $rq_e); echo $rq_h ? e(rtrim(rtrim(number_format($rq_h['hours'], 1), '0'), '.') . ' hrs') : '-'; ?></b><?php if ($rq_h && $rq_h['lunch']): ?><small>1-hr lunch break not counted</small><?php endif; ?></div>
                    </div>
                    <?php if ($rq_clash): $rq_clash['location'] = $rq_clash['location'] ?? ''; ?>
                      <div class="rq-clash"><i class="fa-solid fa-triangle-exclamation"></i> <?= e(training_conflict_message($rq_clash)) ?> This request can't be approved; decline it with a reason so the barangay can pick another schedule.</div>
                    <?php endif; ?>
                    <input type="hidden" name="drill_date" value="<?= e($training_request['requested_date']) ?>"><input type="hidden" name="start_time" value="<?= e($rq_s) ?>"><input type="hidden" name="end_time" value="<?= e($rq_e) ?>"><input type="hidden" name="participants" value="<?= (int)$training_request['participants'] ?>">
                  </div>
                <?php else: ?>
                <div class="wide" style="font-size:13px;font-weight:700;color:#344054;">Schedule if approved <small style="font-weight:400;color:#667085;">(this older request has no time; choose one)</small></div>
                <?= training_time_fields_html(['date' => $training_request['requested_date'], 'start' => '08:00', 'end' => '17:00', 'participants' => (int)$training_request['participants']], false) ?>
                <?php endif; ?>
                <div><button class="btn-small" style="background:#2d7a5c;" name="training_status" value="approved" type="submit"<?= $rq_clash ? ' disabled title="This schedule is already booked"' : '' ?>>Approve &amp; Schedule</button> <button class="btn-small" style="background:#ef4444;" name="training_status" value="rejected" type="submit">Decline &amp; Reply</button></div>
              </form>
            </div>
          <?php endforeach; endif; ?>
        </section>

        <section class="training-panel" role="tabpanel" id="panel-sheets" aria-labelledby="tab-sheets"<?= $training_tab === 'sheets' ? '' : ' hidden' ?>><div class="training-panel-head"><h3>Scheduled Sheets</h3></div><p class="training-empty" style="margin:0 0 12px;">Every drill scheduled by the DRRM Office or approved from a barangay request, saved by month. Click a day to see its drills.</p>
          <div class="drill-cal" id="drillCal">
            <div class="drill-cal-head">
              <button type="button" class="drill-cal-nav" id="calPrev" aria-label="Previous month">&lsaquo;</button>
              <b id="calTitle"></b>
              <button type="button" class="drill-cal-nav" id="calNext" aria-label="Next month">&rsaquo;</button>
              <button type="button" class="drill-cal-today" id="calToday">This month</button>
            </div>
            <div class="drill-cal-grid" id="calGrid"></div>
            <p class="drill-cal-sum" id="calSum"></p>
          </div>
          <?php if (!$training_drills): ?><p class="training-empty">No scheduled drills yet.</p><?php else: foreach ($training_drills as $training_drill): ?>
            <article class="schedule-sheet" data-date="<?= e(date('Y-m-d', strtotime($training_drill['scheduled_at']))) ?>" data-time="<?= e(date('g:i A', strtotime($training_drill['scheduled_at']))) ?>" data-name="<?= e($training_drill['training_name']) ?>" <?php $live = training_live_status($training_drill); ?> data-status="<?= e($live) ?>"><header><h3><?= e($training_drill['training_name']) ?></h3><span class="drill-st <?= e($live) ?>"><?= e(strtoupper(training_status_label($live))) ?></span></header>
              <p><strong>Date:</strong> <?= e(training_when_text($training_drill)) ?></p><p><strong>Location:</strong> <?= e($training_drill['location']) ?></p><p><strong>Barangays:</strong> <?= e(drill_barangays_text($training_drill['barangays'] ?? '')) ?></p><p><strong>Scope:</strong> <?= e($training_drill['scope']) ?></p><p><strong>Participants:</strong> <?= e(training_participants_text($training_drill['participants'])) ?> · <strong>Duration:</strong> <?= e(training_duration_short($training_drill)) ?></p>
              <?php if ($training_drill['status'] === 'scheduled' && $live !== 'completed'): ?>
                <details class="drill-cancel"><summary><i class="fa-solid fa-ban"></i> Cancel this drill</summary>
                  <form method="post" class="drill-cancel-form" onsubmit="return confirm('Cancel this drill? The barangays will be notified.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="drill_id" value="<?= (int)$training_drill['id'] ?>">
                    <label>Reason for cancelling <small>(sent to the barangays)</small></label>
                    <textarea name="cancel_reason" rows="2" maxlength="500" required placeholder="e.g. Venue not available, typhoon signal raised"></textarea>
                    <button type="submit" name="cancel_training_drill" value="1"><i class="fa-solid fa-ban"></i> Confirm cancel</button>
                  </form>
                </details>
              <?php endif; ?>
            </article>
          <?php endforeach; endif; ?>
        </section>
      </div>
    </main>
  </div>
</div>
<div class="training-storage-modal" id="trainingStorageModal" role="dialog" aria-modal="true" aria-labelledby="trainingStorageTitle">
  <div class="training-storage-dialog">
    <div class="training-storage-header"><h3 id="trainingStorageTitle">Stored Training Requests</h3><button class="training-storage-close" id="closeTrainingStorage" type="button" aria-label="Close request storage">&times;</button></div>
    <?php if (!$handled_training_requests): ?><p class="training-empty">No approved or declined training requests yet.</p><?php else: foreach ($handled_training_requests as $handled_request): ?>
      <div class="training-storage-item"><strong><?= e($handled_request['training_name']) ?> - <?= e($handled_request['fullname']) ?></strong><br>
        <span><?= e(barangay_label($handled_request['barangay'])) ?> · <?= e($handled_request['position']) ?> · <strong><?= e(ucfirst($handled_request['status'])) ?></strong></span><br>
        Preferred date: <?= e(date('F j, Y', strtotime($handled_request['requested_date']))) ?> · <?= e($handled_request['location']) ?><br><?= e($handled_request['details']) ?>
        <?php if (!empty($handled_request['reviewed_at'])): ?><br>Reviewed: <?= e(date('F j, Y', strtotime($handled_request['reviewed_at']))) ?><?php endif; ?>
        <?php if (!empty($handled_request['admin_reply'])): ?><br>Admin reply: <?= e($handled_request['admin_reply']) ?><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<script>
  // Section buttons: show one panel at a time, remembered in the URL so a refresh stays on the same section.
  (function () {
    const tabs = Array.prototype.slice.call(document.querySelectorAll('.training-tab'));
    function show(name, focus) {
      tabs.forEach(function (tab) {
        const on = tab.dataset.tab === name;
        tab.classList.toggle('active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
        document.getElementById('panel-' + tab.dataset.tab).hidden = !on;
        if (on && focus) tab.focus();
      });
      try { history.replaceState(null, '', name === 'schedule' ? 'admin_training.php' : 'admin_training.php?tab=' + name); } catch (err) {}
    }
    tabs.forEach(function (tab, i) {
      tab.tabIndex = tab.classList.contains('active') ? 0 : -1;
      tab.addEventListener('click', function () { show(tab.dataset.tab, false); });
      tab.addEventListener('keydown', function (e) { // arrow keys move between the three buttons
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        show(next.dataset.tab, true);
      });
    });
  })();
  const trainingStorageModal = document.getElementById('trainingStorageModal');
  document.getElementById('openTrainingStorage').addEventListener('click', function () { trainingStorageModal.classList.add('is-open'); });
  document.getElementById('closeTrainingStorage').addEventListener('click', function () { trainingStorageModal.classList.remove('is-open'); });
  trainingStorageModal.addEventListener('click', function (event) { if (event.target === trainingStorageModal) trainingStorageModal.classList.remove('is-open'); });
</script>
<script>
(function () {
  // Schedule New Drill: choose a place from the dropdown, then tick any of its barangays.
  // Ticks are kept when switching places, so barangays from several places can be chosen.
  var grid = document.getElementById('drillBrgyGrid'); if (!grid) return;
  var all = document.getElementById('drillAll'), count = document.getElementById('drillBrgyCount'), place = document.getElementById('drillPlace');
  var box = document.getElementById('drillPlaceBox'), title = document.getElementById('drillPlaceTitle'), placeAll = document.getElementById('drillPlaceAll'), picked = document.getElementById('drillPicked');
  var chips = Array.prototype.slice.call(grid.querySelectorAll('.drill-chip'));
  var boxes = chips.map(function (c) { return c.querySelector('input'); });
  function inPlace() { return chips.filter(function (c) { return c.dataset.place === place.value; }); }
  function update() {
    var on = boxes.filter(function (b) { return b.checked; });
    box.classList.toggle('disabled', all.checked);
    place.disabled = all.checked;
    count.textContent = all.checked ? 'All ' + boxes.length + ' barangays' : (on.length ? on.length + ' barangay' + (on.length === 1 ? '' : 's') + ' selected' : 'None selected');
    if (all.checked) picked.innerHTML = '<b>Selected:</b> every South Caloocan barangay.';
    else if (!on.length) picked.textContent = 'No barangay selected yet. Choose a place, then tick the barangays.';
    else {
      var nums = on.map(function (b) { return +b.value; }).sort(function (x, y) { return x - y; });
      picked.innerHTML = '<b>Selected:</b> ' + nums.map(function (n) { return '<span class="drill-tag" data-n="' + n + '">Brgy ' + n + ' <i title="Remove">&times;</i></span>'; }).join('');
    }
    var list = inPlace(), allOn = list.length && list.every(function (c) { return c.querySelector('input').checked; });
    placeAll.hidden = !place.value || all.checked;
    placeAll.textContent = allOn ? 'Unselect all in this place' : 'Select all in this place';
  }
  place.addEventListener('change', function () {
    chips.forEach(function (c) { c.hidden = c.dataset.place !== place.value; });
    box.hidden = !place.value;
    title.textContent = place.value ? place.value + ': tick the barangays that will join' : '';
    update();
  });
  placeAll.addEventListener('click', function () {
    var list = inPlace(), allOn = list.every(function (c) { return c.querySelector('input').checked; });
    list.forEach(function (c) { c.querySelector('input').checked = !allOn; });
    update();
  });
  picked.addEventListener('click', function (e) {
    var t = e.target.closest('.drill-tag i'); if (!t) return;
    var b = grid.querySelector('input[value="' + t.parentNode.dataset.n + '"]'); if (b) b.checked = false; update();
  });
  all.addEventListener('change', update);
  grid.addEventListener('change', update);
  document.getElementById('drillClear').addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); all.checked = false; update(); });
  grid.closest('form').addEventListener('submit', function (e) {
    if (!all.checked && !boxes.some(function (b) { return b.checked; })) { e.preventDefault(); alert('Choose at least one barangay for this drill, or tick All South Caloocan barangays.'); }
  });
  update();
})();
</script>
<?= training_time_assets() ?>
<script>
(function () {
  // Scheduled Sheets calendar: fills the screen; click a drill (or a day) to see its details in a popup.
  var grid = document.getElementById('calGrid'); if (!grid) return;
  var cal = document.getElementById('drillCal');
  var sheets = Array.prototype.slice.call(document.querySelectorAll('.schedule-sheet[data-date]'));
  sheets.forEach(function (s) { s.hidden = true; }); // the details are shown in the popup instead
  var byDay = {};
  sheets.forEach(function (s, i) { s.dataset.idx = i; (byDay[s.dataset.date] = byDay[s.dataset.date] || []).push(s); });
  var now = new Date(), y = now.getFullYear(), m = now.getMonth();
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var esc = function (t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };

  // Popup
  var modal = document.createElement('div');
  modal.className = 'drill-pop'; modal.hidden = true;
  modal.innerHTML = '<div class="drill-pop-box" role="dialog" aria-modal="true"><div class="drill-pop-head"><b id="drillPopTitle"></b><button type="button" class="drill-pop-x" aria-label="Close">&times;</button></div><div class="drill-pop-body" id="drillPopBody"></div></div>';
  document.body.appendChild(modal);
  function openPop(title, list) {
    document.getElementById('drillPopTitle').textContent = title;
    document.getElementById('drillPopBody').innerHTML = list.map(function (s) { return '<article class="schedule-sheet">' + s.innerHTML + '</article>'; }).join('');
    modal.hidden = false;
  }
  function closePop() { modal.hidden = true; }
  modal.addEventListener('click', function (e) { if (e.target === modal || e.target.closest('.drill-pop-x')) closePop(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closePop(); });

  // Calendar height: fill the rest of the screen so the whole month shows without scrolling.
  function scroller(el) { while (el && el !== document.body) { var o = getComputedStyle(el).overflowY; if ((o === 'auto' || o === 'scroll') && el.scrollHeight > el.clientHeight) return el; el = el.parentElement; } return null; }
  function fit() {
    if (!cal.offsetParent) return; // the tab is hidden
    var sc = scroller(cal.parentElement), top = cal.getBoundingClientRect().top + (sc ? sc.scrollTop : window.scrollY);
    var h = Math.max(460, window.innerHeight - top - 24);
    cal.style.height = h + 'px';
    fitEvents();
  }
  // Show only the drills that fit inside each day box; the rest are shown as "+N more" (opens the full list).
  function fitEvents() {
    grid.querySelectorAll('.drill-cal-day.has').forEach(function (c) {
      var evs = Array.prototype.slice.call(c.querySelectorAll('.drill-cal-ev[data-idx]')), more = c.querySelector('.drill-cal-ev.more');
      var total = (c.dataset.total = c.dataset.total || (evs.length + (more ? +more.textContent.replace(/\D/g, '') : 0)));
      evs.forEach(function (e) { e.hidden = false; });
      if (!more) { more = document.createElement('span'); more.className = 'drill-cal-ev more'; c.appendChild(more); }
      more.hidden = true;
      // How many drill bars fit under the day number (always at least one).
      var cap = Math.max(1, Math.floor((c.clientHeight - 30) / 24)), shown = Math.min(evs.length, cap);
      evs.forEach(function (e, i) { e.hidden = i >= shown; });
      if (+total > shown) { more.hidden = false; more.textContent = '+' + (total - shown) + ' more'; }
    });
  }
  function draw() {
    var first = new Date(y, m, 1), days = new Date(y, m + 1, 0).getDate(), key = y + '-' + pad(m + 1);
    document.getElementById('calTitle').textContent = first.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    var h = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(function (d) { return '<div class="drill-cal-dow">' + d + '</div>'; }).join('');
    for (var i = 0; i < first.getDay(); i++) h += '<div class="drill-cal-day other"></div>';
    var cells = first.getDay() + days, todayKey = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()), total = 0;
    for (var d = 1; d <= days; d++) {
      var k = key + '-' + pad(d), ev = byDay[k] || [];
      total += ev.length;
      h += '<div class="drill-cal-day' + (ev.length ? ' has' : '') + (k === todayKey ? ' today' : '') + '" data-day="' + k + '"><b>' + d + '</b>'
        + ev.slice(0, 3).map(function (s) { return '<span class="drill-cal-ev ' + s.dataset.status + '" data-idx="' + s.dataset.idx + '" title="' + esc(s.dataset.name) + '">' + esc(s.dataset.time + ' ' + s.dataset.name) + '</span>'; }).join('')
        + (ev.length > 3 ? '<span class="drill-cal-ev more">+' + (ev.length - 3) + ' more</span>' : '') + '</div>';
    }
    for (var t = cells; t % 7; t++) h += '<div class="drill-cal-day other"></div>';
    grid.innerHTML = h;
    grid.dataset.fitted = '';
    grid.style.gridTemplateRows = 'auto repeat(' + Math.ceil(cells / 7) + ', minmax(0, 1fr))';
    document.getElementById('calSum').innerHTML = '<strong>' + total + '</strong> drill' + (total === 1 ? '' : 's') + ' saved for ' + document.getElementById('calTitle').textContent + (total ? ' · click a drill to see its details' : '') + '<span class="drill-cal-legend"><span><i style="background:linear-gradient(135deg,#ea7a00,#3f9b46)"></i>Upcoming</span><span><i style="background:#d92d20"></i>Ongoing now</span><span><i style="background:#98a2b3"></i>Completed (automatic)</span></span>';
    fit();
  }
  grid.addEventListener('click', function (e) {
    var ev = e.target.closest('.drill-cal-ev[data-idx]');
    if (ev) { var s = sheets[+ev.dataset.idx]; openPop(s.dataset.name, [s]); return; }
    var c = e.target.closest('.drill-cal-day.has'); if (!c) return;
    openPop(new Date(c.dataset.day + 'T00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }), byDay[c.dataset.day]);
  });
  document.getElementById('calPrev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } draw(); });
  document.getElementById('calNext').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } draw(); });
  document.getElementById('calToday').addEventListener('click', function () { y = now.getFullYear(); m = now.getMonth(); draw(); });
  window.addEventListener('resize', fit);
  var tab = document.getElementById('tab-sheets'); if (tab) tab.addEventListener('click', function () { setTimeout(fit, 30); });
  draw();
})();
</script><script>
// Declining a request needs a reason (the barangay is told why); approving does not.
document.addEventListener('click', function (e) {
  var b = e.target.closest('button[value="rejected"]'); if (!b) return;
  var form = b.closest('form'), note = form && form.querySelector('.review-note'); if (!note) return;
  if (!note.value.trim()) { e.preventDefault(); note.focus(); note.style.borderColor = '#d92d20'; note.style.boxShadow = '0 0 0 3px rgba(217,45,32,.18)'; note.placeholder = 'Type the reason for declining here (required)'; alert('Please enter the reason for declining so the barangay knows why.'); }
});
</script>
<script>
document.querySelectorAll('form').forEach(function (f) { if (!f.querySelector('[name=create_training_drill],[name=respond_training_request]')) return; f.addEventListener('submit', function (e) { if (e.defaultPrevented) return; if (f.dataset.sent) { e.preventDefault(); return; } f.dataset.sent = '1'; }); });
</script>
</body>
</html>
