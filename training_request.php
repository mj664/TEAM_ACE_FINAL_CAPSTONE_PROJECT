<?php
require_once 'init.php';
require_login();
if (is_role_in(['head_admin', 'official', 'admin'])) { header('Location: admin_training.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
require_once __DIR__ . '/training_time.php';
$notice = '';
$error = '';
$training_names = ['Flood Response Exercise', 'Medical Emergency Response Training', 'Earthquake Preparedness Drill', 'Other Training or Drill'];

// Same booking rules the DRRM Office uses when approving (training_conflict): a schedule that clashes with a booked
// drill (same location, or this barangay already busy at that time) is stopped here, before it reaches the admin.
// Also stops a second request for a time this barangay already has a pending request for. Returns a message or null.
function tr_request_clash(mysqli $conn, $user_id, $date, $start, $end, $location) {
  $b = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
  $b->bind_param('i', $user_id); $b->execute();
  $brgy = (string)(int)($b->get_result()->fetch_assoc()['barangay'] ?? 0); $b->close();
  $d = training_conflict($conn, "$date $start:00", "$date $end:00", $location, $brgy);
  if ($d) {
    return $d['clash'] === 'location'
      ? 'Schedule conflict: ' . $d['location'] . ' is already booked for "' . $d['training_name'] . '" on ' . training_when_text($d) . '. Choose another date, time or location.'
      : 'Schedule conflict: your barangay already has "' . $d['training_name'] . '" scheduled on ' . training_when_text($d) . '. Choose another date or time.';
  }
  $p = $conn->prepare("SELECT training_name FROM training_requests WHERE user_id = ? AND status = 'pending' AND requested_date = ? AND start_time < ? AND end_time > ? LIMIT 1");
  $e = $end . ':00'; $st = $start . ':00';
  $p->bind_param('isss', $user_id, $date, $e, $st); $p->execute();
  $own = $p->get_result()->fetch_assoc(); $p->close();
  if ($own) return 'You already sent a request for "' . $own['training_name'] . '" at this date and time, and it is still waiting for the DRRM Office. Choose another time, or wait for its reply.';
  return null;
}

// Live check while the barangay fills in the form (no saving).
if (isset($_GET['check_clash'])) {
  header('Content-Type: application/json');
  $date = (string)($_GET['date'] ?? ''); $start = (string)($_GET['start'] ?? ''); $end = (string)($_GET['end'] ?? '');
  $times = training_time_options(); $dt = DateTime::createFromFormat('Y-m-d', $date);
  if (!$dt || $dt->format('Y-m-d') !== $date || !isset($times[$start], $times[$end]) || !training_hours($start, $end)) { echo json_encode(['clash' => null]); exit; }
  echo json_encode(['clash' => tr_request_clash($conn, $user_id, $date, $start, $end, trim((string)($_GET['location'] ?? '')))]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_training_request'])) {
  $training_name = trim((string)($_POST['training_name'] ?? ''));
  [$req_time, $time_error] = training_time_from_post(true);
  $training_date = $req_time['date'] ?? '';
  $training_location = trim((string)($_POST['training_location'] ?? ''));
  $training_details = trim((string)($_POST['training_details'] ?? ''));
  $date = DateTime::createFromFormat('Y-m-d', $training_date);
  if ($time_error) {
    $error = $time_error;
  } elseif (!in_array($training_name, $training_names, true) || !$date || $date->format('Y-m-d') !== $training_date || $training_location === '' || $training_details === '') {
    $error = 'Complete all training request details before submitting.';
  } elseif ($clash = tr_request_clash($conn, $user_id, $training_date, $req_time['start'], $req_time['end'], $training_location)) {
    $error = $clash;
  } else {
    $stmt = $conn->prepare('INSERT INTO training_requests (user_id, training_name, requested_date, location, details, start_time, end_time, participants) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
      $req_part = (string)(int)$req_time['participants'];
      $stmt->bind_param('isssssss', $user_id, $training_name, $training_date, $training_location, $training_details, $req_time['start'], $req_time['end'], $req_part);
      $notice = $stmt->execute() ? 'Your request was sent to the DRRM Office.' : 'Unable to submit your request.';
      $stmt->close();
    } else $error = 'Unable to prepare your request.';
  }
}

// Drills the DRRM Office scheduled for this barangay (or for every barangay), upcoming first.
$my_drills = [];
$me_stmt = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
$me_stmt->bind_param('i', $user_id);
$me_stmt->execute();
$my_barangay = (string)((int)($me_stmt->get_result()->fetch_assoc()['barangay'] ?? 0));
$me_stmt->close();
$has_col = $conn->query("SHOW COLUMNS FROM training_drills LIKE 'barangays'");
if ($has_col && $has_col->num_rows > 0 && $my_barangay !== '0') {
  $drill_stmt = $conn->prepare("SELECT * FROM training_drills WHERE status <> 'cancelled' AND scheduled_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND (barangays = 'all' OR FIND_IN_SET(?, barangays)) ORDER BY scheduled_at ASC");
  $drill_stmt->bind_param('s', $my_barangay);
  $drill_stmt->execute();
  $my_drills = $drill_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $drill_stmt->close();
}

$requests = [];
$stmt = $conn->prepare('SELECT * FROM training_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC');
if ($stmt) {
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) $requests[] = $row;
  $stmt->close();
}
?>
<!doctype html>
<html><head>
  <meta charset="utf-8"><title>Training &amp; Drills</title><?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>"><meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .training-shell { flex:1; min-height:0; height:100vh; }
    .training-scroll { flex:1; overflow-y:auto; padding:24px; }
    .training-panel { max-width:900px; margin:0 auto 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:22px; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .training-panel h3, .layout .training-panel h3 { margin:0 0 6px; color:#1f2937; background:none; padding:0; border-radius:0; }
    .layout .training-panel { background:rgba(255,255,255,.95); border:1px solid rgba(255,255,255,.7); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
    .training-panel input:focus, .training-panel select:focus, .training-panel textarea:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .training-panel p { color:#667085; font-size:13px; }
    .training-form { display:grid; gap:14px; margin-top:18px; }
    .training-form label { display:grid; gap:6px; color:#344054; font-size:13px; font-weight:600; }
    .training-form input, .training-form select, .training-form textarea { width:100%; box-sizing:border-box; padding:10px; border:1px solid #d0d5dd; border-radius:5px; font:inherit; }
    .training-form textarea { min-height:100px; resize:vertical; }
    .tr-clash { padding:10px 12px; border-radius:8px; background:#fef3f2; border:1px solid #fda29b; color:#b42318; font-size:13px; line-height:1.5; }
    .tr-clash::before { content:"\26A0  "; }
    #trSend:disabled { opacity:.5; cursor:not-allowed; }
    .request-card { border-top:1px solid #eaecf0; padding:16px 0 0; margin-top:16px; color:#475467; font-size:13px; }
    .request-card strong { color:#1d2939; }
    .request-status { display:inline-block; margin-left:8px; padding:4px 8px; border-radius:12px; font-size:11px; font-weight:700; background:#fef3c7; color:#92400e; }
    .request-status.approved { background:#d1fae5; color:#065f46; }.request-status.rejected { background:#fee2e2; color:#991b1b; }
    .request-storage-button { border:0; border-radius:6px; padding:9px 13px; background:#2d7a5c; color:#fff; font-weight:700; cursor:pointer; }
    .request-storage-toolbar { max-width:900px; margin:0 auto 14px; display:flex; justify-content:flex-end; }
    .request-storage-button:hover { background:#245f48; }
    .request-storage-modal { display:none; position:fixed; inset:0; z-index:20; padding:7vh 18px; background:rgba(16,24,40,.5); }
    .request-storage-modal.is-open { display:block; }
    .request-storage-dialog { max-width:700px; max-height:85vh; overflow-y:auto; margin:0 auto; background:#fff; border-radius:12px; padding:22px; box-shadow:0 16px 40px rgba(16,24,40,.2); }
    .request-storage-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
    .request-storage-header h3 { margin:0; }
    .request-storage-close { border:0; background:transparent; color:#667085; font-size:22px; cursor:pointer; }
    .request-storage-item { border-top:1px solid #eaecf0; padding:14px 0; color:#475467; font-size:13px; line-height:1.6; }
    .request-storage-item strong { color:#1d2939; }
    .request-storage-empty { color:#667085; font-size:13px; }
    @media(max-width:1200px){.training-shell{height:auto}.training-scroll{overflow:visible}}
    .request-storage-top { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:10px; font-size:14px; box-shadow:0 6px 16px rgba(234,122,0,.3); }
    .request-storage-top:hover, .request-storage-top:focus-visible { filter:brightness(1.08); outline:none; transform:translateY(-1px); }
    @media (max-width:640px) { .request-storage-top { width:100%; justify-content:center; } }
    .drill-card { display:flex; gap:14px; margin-top:14px; padding:14px; border:1px solid #f0e6da; border-left:4px solid #ea7a00; border-radius:10px; background:#fffaf4; color:#475467; font-size:13px; line-height:1.6; }
    .drill-card strong { color:#1d2939; font-size:15px; }
    .drill-date { flex:none; width:58px; text-align:center; border-radius:10px; background:#fff; border:1px solid #f5c48a; padding:6px 0; align-self:flex-start; }
    .drill-date b { display:block; font-size:12px; color:#c56a00; text-transform:uppercase; }
    .drill-date span { display:block; font-size:24px; font-weight:800; color:#1f2937; line-height:1.1; }
    .drill-scope { display:block; margin-top:4px; color:#667085; white-space:pre-wrap; }
    .tr-tabs { max-width:900px; margin:0 auto 14px; display:flex; flex-wrap:wrap; gap:8px; }
    .tr-tab { display:inline-flex; align-items:center; gap:8px; margin:0; padding:11px 18px; border:1px solid rgba(255,255,255,.7); border-radius:12px; background:rgba(255,255,255,.78); color:#9a4a00; font:inherit; font-size:14px; font-weight:800; cursor:pointer; box-shadow:0 4px 12px rgba(120,60,0,.08); }
    .tr-tab:hover { background:#fff; }
    .tr-tab.active { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; border-color:transparent; box-shadow:0 6px 16px rgba(234,122,0,.3); }
    .tr-count { min-width:22px; height:22px; padding:0 6px; border-radius:999px; background:#f2f4f7; color:#475467; font-size:12px; display:inline-grid; place-items:center; }
    .tr-count.has { background:#d92d20; color:#fff; }
    .tr-tab.active .tr-count { background:rgba(255,255,255,.3); color:#fff; }
    .training-panel[hidden] { display:none !important; }
  </style>
</head><body><div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="training-shell" style="display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;"><div><h2 style="margin:0;font-size:24px;">Training &amp; Drills</h2><p style="margin:4px 0 0;color:#666;font-size:13px;">Request a disaster response drill or training schedule from the DRRM Office.</p></div><button class="request-storage-button request-storage-top" id="openTrainingStorage" type="button"><i class="fa-solid fa-box-archive"></i> My Requests (<?= count($requests) ?>)</button></header>
    <main class="training-scroll">
      <?php if ($notice): ?><div class="notice training-panel"><?php echo e($notice); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="errors training-panel"><?php echo e($error); ?></div><?php endif; ?>
      <div class="tr-tabs" role="tablist" aria-label="Training sections">
        <button type="button" class="tr-tab" data-tr-view="drills" role="tab" aria-selected="false">📅 Scheduled drills for your barangay <b class="tr-count<?= $my_drills ? ' has' : '' ?>"><?= count($my_drills) ?></b></button>
        <button type="button" class="tr-tab active" data-tr-view="request" role="tab" aria-selected="true">📝 Request a Drill or Training</button>
      </div>
      <section class="training-panel" data-tr-panel="drills" hidden>
        <h3>📅 Scheduled drills for your barangay</h3><p>Drills and trainings the DRRM Office scheduled for <?= e(barangay_label($my_barangay)) ?>. You are also notified in Messages when a new one is scheduled.</p>
        <?php if (!$my_drills): ?><p class="request-storage-empty" style="margin-top:12px;">No upcoming drills scheduled for your barangay yet.</p><?php else: foreach ($my_drills as $dr): ?>
          <div class="drill-card"><div class="drill-date"><b><?= e(date('M', strtotime($dr['scheduled_at']))) ?></b><span><?= e(date('j', strtotime($dr['scheduled_at']))) ?></span></div>
            <div><strong><?= e($dr['training_name']) ?></strong><?php $live = training_live_status($dr); ?><span class="request-status <?= $live === 'completed' ? 'approved' : ($live === 'ongoing' ? 'rejected' : '') ?>" style="margin-left:8px;"><?= e(training_status_label($live)) ?></span><br>
              <?= e(training_when_text($dr)) ?> · <?= e($dr['location']) ?><br>
              Participants: <?= e(training_participants_text($dr['participants'])) ?> · Duration: <?= e(training_duration_short($dr)) ?><?= $dr['barangays'] === 'all' ? ' · <em>All South Caloocan barangays</em>' : '' ?><br>
              <span class="drill-scope"><?= e($dr['scope']) ?></span></div></div>
        <?php endforeach; endif; ?>
      </section>
      <section class="training-panel" data-tr-panel="request">
        <h3>Request a Drill or Training</h3><p>Give the preferred date, location, and the details the admin needs to plan your activity.</p>
        <form class="training-form" method="post">
          <input type="hidden" name="submit_training_request" value="1">
          <label>Training or drill<select name="training_name" required><option value="">Select an activity</option><?php foreach ($training_names as $name): ?><option value="<?= e($name) ?>"<?= $error && ($_POST['training_name'] ?? '') === $name ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
          <?= training_time_fields_html(['date' => $_POST['drill_date'] ?? '', 'start' => $_POST['start_time'] ?? '08:00', 'end' => $_POST['end_time'] ?? '17:00', 'participants' => $_POST['participants'] ?? 0], true, date('Y-m-d')) ?>
          <label>Preferred location<input type="text" name="training_location" placeholder="Barangay hall, school, or other location" required value="<?= $error ? e((string)($_POST['training_location'] ?? '')) : '' ?>"></label>
          <label>Request details<textarea name="training_details" placeholder="Scope, topics, or other requirements" required><?= $error ? e((string)($_POST['training_details'] ?? '')) : '' ?></textarea></label>
          <div class="tr-clash" id="trClash" role="alert" hidden></div>
          <button type="submit" class="btn" id="trSend" style="justify-self:start;">Send Request</button>
        </form>
      </section>
    </main>
  </div>
</div>
<div class="request-storage-modal" id="trainingStorageModal" role="dialog" aria-modal="true" aria-labelledby="trainingStorageTitle">
  <div class="request-storage-dialog">
    <div class="request-storage-header"><h3 id="trainingStorageTitle">My Training Requests</h3><button class="request-storage-close" id="closeTrainingStorage" type="button" aria-label="Close request storage">&times;</button></div>
    <?php if (!$requests): ?><p class="request-storage-empty">No training requests yet.</p><?php else: foreach ($requests as $request): ?>
      <div class="request-storage-item"><strong><?= e($request['training_name']) ?></strong><span class="request-status <?= e($request['status']) ?>"><?= e(ucfirst($request['status'])) ?></span><br>
        Preferred date: <?= e(date('F j, Y', strtotime($request['requested_date']))) ?><?= !empty($request['start_time']) ? ' · ' . e(training_duration_text(substr($request['start_time'], 0, 5), substr($request['end_time'], 0, 5))) : '' ?><?= !empty($request['participants']) ? ' · ' . (int)$request['participants'] . ' participants' : '' ?> · <?= e($request['location']) ?><br><?= e($request['details']) ?>
        <?php if (!empty($request['scheduled_at'])): ?><br><strong>Scheduled:</strong> <?= e(date('F j, Y g:i A', strtotime($request['scheduled_at']))) ?><?php endif; ?>
        <?php if (!empty($request['admin_reply'])): ?><br><strong>Admin reply:</strong> <?= e($request['admin_reply']) ?><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<script>
  const trainingStorageModal = document.getElementById('trainingStorageModal');
  document.getElementById('openTrainingStorage').addEventListener('click', function () { trainingStorageModal.classList.add('is-open'); });
  document.getElementById('closeTrainingStorage').addEventListener('click', function () { trainingStorageModal.classList.remove('is-open'); });
  trainingStorageModal.addEventListener('click', function (event) { if (event.target === trainingStorageModal) trainingStorageModal.classList.remove('is-open'); });
</script>
<script>
// Training & Drills: the scheduled drills and the request form are shown one at a time, chosen with the tabs.
(function () {
  var tabs = document.querySelectorAll('[data-tr-view]');
  function show(view) {
    tabs.forEach(function (t) { var on = t.dataset.trView === view; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.querySelectorAll('[data-tr-panel]').forEach(function (p) { p.hidden = p.dataset.trPanel !== view; });
    history.replaceState(null, '', location.pathname + (view === 'drills' ? '#drills' : ''));
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.trView); }); });
  if (location.hash === '#drills') show('drills');
})();
</script>
<?= training_time_assets() ?>
<script>
(function () {
  var form = document.querySelector('.training-form'); if (!form) return;
  var box = document.getElementById('trClash'), send = document.getElementById('trSend'), timer = null, seq = 0;
  function check() {
    var date = form.drill_date.value, loc = form.training_location.value.trim();
    if (!date) { box.hidden = true; send.disabled = false; return; }
    var my = ++seq;
    fetch('training_request.php?check_clash=1&date=' + encodeURIComponent(date) + '&start=' + encodeURIComponent(form.start_time.value)
      + '&end=' + encodeURIComponent(form.end_time.value) + '&location=' + encodeURIComponent(loc), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (my !== seq) return; // an older answer arrived late
        box.textContent = d.clash || ''; box.hidden = !d.clash; send.disabled = !!d.clash;
      }).catch(function () { box.hidden = true; send.disabled = false; });
  }
  ['drill_date', 'start_time', 'end_time'].forEach(function (n) { form[n].addEventListener('change', check); });
  form.training_location.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 400); });
  check();
})();
</script>
</body></html>
