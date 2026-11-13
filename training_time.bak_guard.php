<?php
// Shared by the admin (Training & Drills) and the barangay request form:
// date + start/end time pickers that work out the duration, and a participants picker (10 to 100).

// Columns for the time range and participants (added once).
function training_time_schema() {
    global $conn;
    $add = ['training_drills' => ['end_at' => 'DATETIME NULL DEFAULT NULL'],
        'training_requests' => ['start_time' => 'TIME NULL DEFAULT NULL', 'end_time' => 'TIME NULL DEFAULT NULL', 'participants' => 'VARCHAR(30) NULL DEFAULT NULL']];
    foreach ($add as $table => $cols) foreach ($cols as $col => $def) {
        $r = $conn->query("SHOW COLUMNS FROM $table LIKE '$col'");
        if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE $table ADD $col $def");
    }
}
training_time_schema();

// Automatic status: a scheduled drill becomes "completed" once its end time has passed
// (drills saved before end times existed end at 11:59 PM of their day). Runs on every page that loads this file.
function training_auto_complete() {
    global $conn;
    $res = $conn->query("SELECT id, training_name FROM training_drills WHERE status = 'scheduled'
        AND COALESCE(end_at, CONCAT(DATE(scheduled_at), ' 23:59:59')) < NOW()");
    if (!$res || !$res->num_rows) return 0;
    $n = 0;
    while ($d = $res->fetch_assoc()) {
        $id = (int)$d['id'];
        $conn->query("UPDATE training_drills SET status = 'completed' WHERE id = $id AND status = 'scheduled'");
        if ($conn->affected_rows === 1) { $n++; audit_log('TRAINING_DRILL_COMPLETED', $d['training_name'], 'Marked completed automatically after its scheduled end time', 'Success', 0, 'System', 'system'); }
    }
    return $n;
}
training_auto_complete();

// What to show right now: Upcoming, Ongoing (between start and end), Completed or Cancelled.
function training_live_status(array $d) {
    if ($d['status'] !== 'scheduled') return $d['status'];
    $start = strtotime($d['scheduled_at']);
    $end = training_drill_end($d) ?: strtotime(date('Y-m-d 23:59:59', $start));
    $now = time();
    return ($now >= $start && $now <= $end) ? 'ongoing' : 'upcoming';
}
function training_status_label($st) {
    return ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing now', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$st] ?? ucfirst($st);
}

function training_time_options() {
    $out = [];
    for ($m = 6 * 60; $m <= 22 * 60; $m += 30) $out[sprintf('%02d:%02d', intdiv($m, 60), $m % 60)] = date('g:i A', mktime(0, $m));
    return $out;
}
function training_participant_options() { return range(10, 100, 10); }

// Hours between two "HH:MM" times. A 1-hour lunch break is not counted when the activity runs through 12:00-1:00 PM
// (so 8:00 AM to 5:00 PM is 8 hours).
function training_hours($start, $end) {
    $s = strtotime('2000-01-01 ' . $start); $e = strtotime('2000-01-01 ' . $end);
    if (!$s || !$e || $e <= $s) return null;
    $h = ($e - $s) / 3600;
    $lunch = $s <= strtotime('2000-01-01 12:00') && $e >= strtotime('2000-01-01 13:00');
    if ($lunch) $h -= 1;
    return ['hours' => $h, 'lunch' => $lunch];
}
function training_duration_text($start, $end) {
    $r = training_hours($start, $end);
    if (!$r) return '';
    $h = rtrim(rtrim(number_format($r['hours'], 1), '0'), '.');
    return $h . ' hr' . ($r['hours'] == 1 ? '' : 's') . ' (' . date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end)) . ($r['lunch'] ? ', 1-hr lunch break' : '') . ')';
}

// Reads date / start / end / participants from a form. Returns [data, error].
function training_time_from_post($required = true) {
    $date = trim((string)($_POST['drill_date'] ?? ''));
    $start = trim((string)($_POST['start_time'] ?? ''));
    $end = trim((string)($_POST['end_time'] ?? ''));
    $part = (int)($_POST['participants'] ?? 0);
    $d = DateTime::createFromFormat('Y-m-d', $date);
    $times = training_time_options();
    if (!$d || $d->format('Y-m-d') !== $date) return [null, $required ? 'Choose the date of the drill.' : null];
    if (!isset($times[$start]) || !isset($times[$end])) return [null, 'Choose the start and end time.'];
    if (!training_hours($start, $end)) return [null, 'The end time must be later than the start time.'];
    if (!in_array($part, training_participant_options(), true)) return [null, 'Choose the number of participants.'];
    return [['date' => $date, 'start' => $start, 'end' => $end, 'scheduled_at' => "$date $start:00", 'end_at' => "$date $end:00",
        'duration' => training_duration_text($start, $end), 'participants' => $part . ' participants'], null];
}

// The picker. $v: date, start, end, participants (prefill).
function training_time_fields_html($v = [], $required = true, $min_date = null) {
    $req = $required ? ' required' : '';
    $start = $v['start'] ?? '08:00'; $end = $v['end'] ?? '17:00'; $part = (int)($v['participants'] ?? 0);
    $opt = function ($sel) { $h = ''; foreach (training_time_options() as $k => $l) $h .= '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . $l . '</option>'; return $h; };
    $p = '<option value="">Select</option>';
    foreach (training_participant_options() as $n) $p .= '<option value="' . $n . '"' . ($n === $part ? ' selected' : '') . '>' . $n . ' participants</option>';
    return '<div class="drill-time wide">'
        . '<label>Date<input type="date" name="drill_date" value="' . e($v['date'] ?? '') . '"' . ($min_date ? ' min="' . e($min_date) . '"' : '') . $req . '></label>'
        . '<label>Start time<select name="start_time" class="dt-start">' . $opt($start) . '</select></label>'
        . '<label>End time<select name="end_time" class="dt-end">' . $opt($end) . '</select></label>'
        . '<label>Participants<select name="participants"' . $req . '>' . $p . '</select></label>'
        . '<div class="drill-time-dur"><span>Duration</span><b class="dt-out">-</b></div>'
        . '</div>';
}

// Styles + the live duration script (printed once per page).
function training_time_assets() {
    return <<<'H'
<style>
  .drill-time { display:grid; grid-template-columns:1.2fr 1fr 1fr 1.1fr 1.3fr; gap:10px; align-items:end; }
  .drill-time label { display:grid; gap:6px; margin:0; }
  .drill-time-dur { padding:9px 12px; border-radius:8px; background:#fff1df; border:1px solid #f5c48a; min-height:44px; box-sizing:border-box; }
  .drill-time-dur span { display:block; font-size:11px; font-weight:700; color:#9a4a00; text-transform:uppercase; letter-spacing:.4px; }
  .drill-time-dur b { font-size:16px; color:#1f2937; }
  .drill-time-dur b small { display:block; font-size:11px; font-weight:600; color:#9a4a00; }
  .drill-time-dur.bad { background:#fef3f2; border-color:#fecdca; } .drill-time-dur.bad b { color:#b42318; }
  @media (max-width:900px) { .drill-time { grid-template-columns:1fr 1fr; } }
</style>
<script>
(function () {
  // Start / end time -> duration (a 1-hour lunch break is not counted when the activity runs through 12:00-1:00 PM).
  function mins(v) { var p = v.split(':'); return +p[0] * 60 + +p[1]; }
  function label(m) { var h = Math.floor(m / 60), mm = m % 60, ap = h >= 12 ? 'PM' : 'AM', hh = h % 12 || 12; return hh + ':' + (mm < 10 ? '0' : '') + mm + ' ' + ap; }
  function update(box) {
    var s = mins(box.querySelector('.dt-start').value), e = mins(box.querySelector('.dt-end').value), out = box.querySelector('.dt-out'), wrap = out.parentNode;
    if (e <= s) { out.textContent = 'End time must be after start'; wrap.classList.add('bad'); return false; }
    var lunch = s <= 720 && e >= 780, h = (e - s) / 60 - (lunch ? 1 : 0);
    out.innerHTML = (Math.round(h * 10) / 10) + ' hr' + (h === 1 ? '' : 's') + (lunch ? '<small>1-hr lunch break not counted</small>' : '');
    wrap.classList.remove('bad'); return true;
  }
  document.querySelectorAll('.drill-time').forEach(function (box) {
    box.addEventListener('change', function () { update(box); });
    update(box);
    var form = box.closest('form');
    if (form) form.addEventListener('submit', function (ev) {
      var approving = !ev.submitter || ev.submitter.value !== 'rejected';
      if (approving && !update(box)) { ev.preventDefault(); alert('The end time must be later than the start time.'); }
    });
  });
})();
</script>
H;
}

// ---- One display format for every drill (older drills were saved with only free-text participants/duration) ----
// End time: the saved end_at, or for older drills the start plus the hours in their duration text
// (plus the 1-hour lunch break when it runs past noon, so "8hrs" from 8:00 AM ends at 5:00 PM).
function training_drill_end(array $d) {
    if (!empty($d['end_at'])) return strtotime($d['end_at']);
    $start = strtotime($d['scheduled_at'] ?? '');
    if (!$start || !preg_match('/(\d+(?:\.\d+)?)\s*h/i', (string)($d['duration'] ?? ''), $m)) return null;
    $end = $start + (int)round((float)$m[1] * 3600);
    if ($start <= strtotime(date('Y-m-d 12:00', $start)) && $end > strtotime(date('Y-m-d 12:00', $start))) $end += 3600;
    return $end;
}
// "Thursday, October 15, 2026 · 8:00 AM - 5:00 PM"
function training_when_text(array $d, $date_fmt = 'l, F j, Y') {
    $start = strtotime($d['scheduled_at']); $end = training_drill_end($d);
    return date($date_fmt, $start) . ' · ' . date('g:i A', $start) . ($end ? ' - ' . date('g:i A', $end) : '');
}
// "8 hrs (1-hr lunch break)"
function training_duration_short(array $d) {
    $end = training_drill_end($d);
    if (!$end) return trim((string)($d['duration'] ?? '')) ?: 'To be confirmed';
    $r = training_hours(date('H:i', strtotime($d['scheduled_at'])), date('H:i', $end));
    if (!$r) return trim((string)($d['duration'] ?? ''));
    $h = rtrim(rtrim(number_format($r['hours'], 1), '0'), '.');
    return $h . ' hr' . ($r['hours'] == 1 ? '' : 's') . ($r['lunch'] ? ' (1-hr lunch break)' : '');
}
// "50 participants"
function training_participants_text($p) {
    $p = trim((string)$p);
    if (preg_match('/^(\d+)/', $p, $m)) return (int)$m[1] . ' participant' . ((int)$m[1] === 1 ? '' : 's');
    return $p !== '' ? $p : 'To be confirmed';
}

// ---- Double-booking check ----
// Two drills clash when their times overlap and they use the same place, or the same barangay would have to attend
// both (a drill for "all" barangays involves every barangay). Returns the clashing drill, or null.
function training_location_key($loc) {
    $loc = strtolower(trim((string)$loc));
    $loc = preg_replace('/\bbrgy\.?\b/', 'barangay', $loc);
    return preg_replace('/[^a-z0-9]+/', '', $loc);
}
function training_conflict(mysqli $conn, $start_sql, $end_sql, $location, $barangays, $exclude_id = 0) {
    $start = strtotime($start_sql);
    $end = $end_sql ? strtotime($end_sql) : strtotime(date('Y-m-d 23:59:59', $start));
    $loc = training_location_key($location);
    $mine = $barangays === 'all' ? 'all' : array_filter(array_map('intval', explode(',', (string)$barangays)));
    $day = date('Y-m-d', $start);
    $st = $conn->prepare("SELECT * FROM training_drills WHERE status <> 'cancelled' AND DATE(scheduled_at) = ? AND id <> ? ORDER BY scheduled_at");
    $ex = (int)$exclude_id;
    $st->bind_param('si', $day, $ex);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($rows as $d) {
        $ds = strtotime($d['scheduled_at']);
        $de = training_drill_end($d) ?: strtotime(date('Y-m-d 23:59:59', $ds));
        if (!($ds < $end && $de > $start)) continue; // no time overlap
        $theirs = ($d['barangays'] ?? '') === 'all' ? 'all' : array_filter(array_map('intval', explode(',', (string)($d['barangays'] ?? ''))));
        $same_place = $loc !== '' && training_location_key($d['location']) === $loc;
        $same_brgy = $mine === 'all' || $theirs === 'all' || array_intersect((array)$mine, (array)$theirs);
        if ($same_place || $same_brgy) { $d['clash'] = $same_place ? 'location' : 'barangay'; return $d; }
    }
    return null;
}
function training_conflict_message(array $d) {
    return 'Schedule conflict: "' . $d['training_name'] . '" is already booked ' . ($d['clash'] === 'location' ? 'at ' . $d['location'] : 'for ' . (function_exists('drill_barangays_text') ? drill_barangays_text($d['barangays'] ?? '') : 'the same barangay'))
        . ' on ' . training_when_text($d) . '. Choose another ' . ($d['clash'] === 'location' ? 'date, time or location.' : 'date or time.');
}
