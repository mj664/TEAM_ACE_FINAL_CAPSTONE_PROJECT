<?php
require_once 'init.php';
require_login();
require_role(['head_admin', 'admin', 'official', 'user']); // DRRMC Officials and the City Head see every barangay; barangay staff see only their own
require_once 'forecast_engine.php';
require_once 'south_caloocan_map.php';

$user_id = (int)$_SESSION['user_id'];
$barangays = forecast_barangays();
$is_staff = !is_role_in(['head_admin', 'admin', 'official']);
if ($is_staff) {
    // Barangay staff: locked to their own barangay (read-only, except refreshing their own forecast).
    $me_stmt = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
    $me_stmt->bind_param('i', $user_id);
    $me_stmt->execute();
    $my_brgy = (int)preg_replace('/\D+/', '', (string)($me_stmt->get_result()->fetch_assoc()['barangay'] ?? ''));
    $me_stmt->close();
    $barangays = isset($barangays[$my_brgy]) ? [$my_brgy => $barangays[$my_brgy]] : [];
    $selected = $my_brgy;
} else {
    $selected = (int)($_GET['b'] ?? ($_POST['b'] ?? (array_key_first($barangays) ?? 0)));
}

// Actions: generate a forecast (one barangay or all), mark a prediction as resolved.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $_SESSION['forecast_flash'] = ['err', 'Your session expired. Please try again.'];
    } elseif (isset($_POST['generate'])) {
        set_time_limit(300);
        session_write_close();
        $targets = ($_POST['generate'] === 'all' && !$is_staff) ? array_keys($barangays) : [$selected];
        $alerts = 0; $insufficient = 0; $sources = []; $summary = '';
        foreach ($targets as $b) {
            if (!isset($barangays[$b])) continue;
            $r = forecast_run($b, $user_id);
            $alerts += $r['alerts']; $insufficient += $r['insufficient']; $sources[$r['source']] = true;
            if (count($targets) === 1) $summary = $r['summary'];
        }
        session_start();
        $_SESSION['forecast_flash'] = ['ok', 'Forecast generated for ' . count($targets) . ' barangay(s): ' . $alerts . ' restock alert(s)'
            . ($insufficient ? ', ' . $insufficient . ' item(s) with insufficient data' : '') . '. '
            
            . ($summary !== '' ? ' ' . $summary : '')];
    } elseif (isset($_POST['resolve']) && !$is_staff) {
        $pid = (int)$_POST['resolve'];
        $stmt = $conn->prepare('UPDATE ml_prediction SET is_resolved = 1 WHERE prediction_id = ?');
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $stmt->close();
        audit_log('ML_PREDICTION_RESOLVED', 'prediction #' . $pid, 'Restocking recommendation marked as acted upon');
        $_SESSION['forecast_flash'] = ['ok', 'Recommendation marked as resolved.'];
    }
    header('Location: forecast.php?b=' . $selected);
    exit;
}
$flash = $_SESSION['forecast_flash'] ?? null;
unset($_SESSION['forecast_flash']);

$rows = $selected ? forecast_latest($selected) : [];
$series = $selected ? forecast_series($selected) : [];

// City overview: latest run of every barangay.
$overview = [];
$res = $conn->query("SELECT p.barangay, MAX(p.created_at) AS at, SUM(p.status = 'alert' AND p.is_resolved = 0) AS alerts, SUM(p.status = 'insufficient') AS insufficient
    FROM ml_prediction p INNER JOIN (SELECT barangay, MAX(prediction_id) AS last_id FROM ml_prediction GROUP BY barangay) l ON l.barangay = p.barangay
    INNER JOIN ml_prediction lr ON lr.prediction_id = l.last_id AND lr.run_id = p.run_id GROUP BY p.barangay ORDER BY alerts DESC, p.barangay");
while ($r = $res->fetch_assoc()) $overview[(int)$r['barangay']] = $r;

// Chart data: one point per day (the last report of that day) plus the model's projection, on a real time scale.
$chart = [];
$min = FORECAST_REORDER_LEVEL;
foreach ($rows as $r) {
    $pts = $series[$r['item_key']] ?? [];
    if (!$pts || $r['status'] === 'insufficient') continue; // nothing to project yet
    $daily = [];
    foreach ($pts as $p) $daily[date('Y-m-d', $p[0])] = $p[1];
    $hist = [];
    foreach ($daily as $day => $qty) $hist[] = ['x' => strtotime($day . ' 12:00') * 1000, 'y' => $qty];
    $start = strtotime(date('Y-m-d') . ' 12:00');
    $now_qty = (int)$r['current_stock']; $rate = (float)$r['daily_rate']; $h = FORECAST_HORIZON_DAYS;
    $proj = [['x' => $start * 1000, 'y' => $now_qty]];
    $zero_day = $rate > 0 ? $now_qty / $rate : null;
    if ($zero_day !== null && $zero_day < $h) $proj[] = ['x' => (int)(($start + $zero_day * 86400) * 1000), 'y' => 0];
    $proj[] = ['x' => ($start + $h * 86400) * 1000, 'y' => max(0, round($now_qty - $rate * $h))];
    // One plain sentence above the chart.
    $fmt = function ($days) use ($start) { return date('M j', (int)($start + $days * 86400)); };
    $per_day = rtrim(rtrim(number_format($rate, 1), '0'), '.');
    if ($now_qty === 0) $msg = ['red', 'Out of stock now.'];
    elseif ($rate <= 0) $msg = [$now_qty <= $min ? 'orange' : 'green', 'No usage seen between reports, so it is expected to stay at ' . $now_qty . ($now_qty <= $min ? ', which is at or below the minimum of ' . $min . '.' : '.')];
    elseif ($zero_day < $h) $msg = ['red', 'Uses about ' . $per_day . ' a day. Expected to run out around ' . $fmt($zero_day) . ' (in about ' . ceil($zero_day) . ' days).'];
    elseif ($now_qty - $rate * $h <= $min) $msg = ['orange', 'Uses about ' . $per_day . ' a day. Expected to drop below the minimum of ' . $min . ' around ' . $fmt(($now_qty - $min) / $rate) . '.'];
    else $msg = ['green', 'Uses about ' . $per_day . ' a day. Expected to still have about ' . max(0, round($now_qty - $rate * $h)) . ' on ' . $fmt($h) . ', above the minimum.'];
    $chart[$r['item_key']] = ['name' => $r['item_name'], 'now' => $now_qty, 'hist' => $hist, 'proj' => $proj, 'msg' => $msg, 'today' => $start * 1000];
}
$equip = $selected ? forecast_equipment_status($selected) : ['out' => [], 'low' => []];
$low_equipment = $equip['low']; $out_equipment = $equip['out'];
$alert_rows = array_filter($rows, function ($r) { return $r['status'] === 'alert' && !$r['is_resolved']; });

// Compare two barangays side by side (not for barangay staff, who only see their own barangay).
// Stock comes from each barangay's latest equipment report; 30-day need and restock status from its latest forecast.
function fc_compare_data($b) {
    global $conn;
    $num = (string)(int)$b;
    $st = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id WHERE u.status = 'approved' AND u.role = 'user' AND u.barangay = ? ORDER BY er.submitted_at DESC, er.id DESC LIMIT 1");
    $st->bind_param('s', $num); $st->execute(); $rep = $st->get_result()->fetch_assoc() ?: []; $st->close();
    $qty = function ($k) use ($rep) { $raw = trim((string)($rep[$k] ?? '')); return ($raw === '' || !is_numeric($raw)) ? null : max(0, (int)$raw); };
    $fc = []; foreach (forecast_latest($b) as $r) $fc[$r['item_key']] = $r;
    $med = []; $sum = ['restock' => 0, 'need' => 0, 'order' => 0, 'tools_out' => 0, 'tools_low' => 0];
    foreach (forecast_items() as $k => $label) {
        $r = $fc[$k] ?? null; $q = $qty($k);
        // Same rule as the rest of the system: 0 = out of stock, 1 to 15 = low; then the forecast (restock soon / not enough data).
        $status = $q === null ? 'nodata' : ($q === 0 ? 'out' : (($r && $r['status'] === 'alert' && !$r['is_resolved']) ? 'alert'
            : ($q <= FORECAST_REORDER_LEVEL ? 'low' : ((!$r || $r['status'] === 'insufficient') ? 'nodata' : 'ok'))));
        if ($status === 'nodata' && $q !== null) $status = 'okfew'; // enough stock now, forecast still learning
        $need = ($r && $r['status'] !== 'insufficient') ? (int)$r['predicted_demand'] : null;
        if (in_array($status, ['alert', 'out', 'low'], true)) $sum['restock']++;
        $sum['need'] += (int)$need;
        if ($r && !$r['is_resolved'] && $r['status'] !== 'insufficient') $sum['order'] += (int)$r['recommended_order'];
        $med[$k] = ['name' => is_array($label) ? ($label['name'] ?? $k) : $label, 'qty' => $q, 'need' => $need, 'status' => $status];
    }
    $tools = [];
    foreach (forecast_equipment_items() as $k => $label) {
        $q = $qty($k); $tools[$k] = ['name' => $label, 'qty' => $q];
        if ($q === 0) $sum['tools_out']++; elseif ($q !== null && $q <= FORECAST_REORDER_LEVEL) $sum['tools_low']++;
    }
    return ['med' => $med, 'tools' => $tools, 'sum' => $sum, 'date' => $rep['submitted_at'] ?? null];
}
$cmp = null;
if (!$is_staff && $selected && isset($barangays[$selected]) && count($barangays) > 1) {
    $cmp_b = (int)($_GET['c'] ?? 0);
    if (!isset($barangays[$cmp_b]) || $cmp_b === $selected) foreach (array_keys($barangays) as $x) if ($x !== $selected) { $cmp_b = $x; break; }
    $cmp = ['a' => $selected, 'b' => $cmp_b, 'A' => fc_compare_data($selected), 'B' => fc_compare_data($cmp_b)];
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Demand Forecast</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .fc-page { max-width:1280px; margin:0 auto; display:grid; gap:18px; }
    .fc-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 4px 14px rgba(16,24,40,.07); overflow:hidden; }
    .fc-card-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; border-bottom:1px solid #eef0f2; flex-wrap:wrap; }
    .fc-card-head h3, .layout .fc-card-head h3 { margin:0; font-size:17px; color:#1f2937; background:none; padding:0; }
    .fc-card-head small { color:#667085; }
    .fc-controls { display:flex !important; flex-direction:row !important; flex-wrap:wrap; gap:10px; align-items:center !important; justify-content:flex-start !important; padding:16px 20px; }
    .fc-controls select { flex:0 1 320px; height:44px !important; min-height:0 !important; width:auto !important; max-width:420px; margin:0 !important; padding:10px 12px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:14px; }
    .fc-controls .fc-btn { width:auto !important; margin:0 !important; display:inline-flex; align-items:center; gap:7px; }
    .fc-btn { border:0; border-radius:8px; padding:11px 18px; font:inherit; font-size:13px; font-weight:800; color:#fff; cursor:pointer; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); }
    .fc-btn.ghost { background:#fff; color:#c56a00; border:1px solid #f5c48a; }
    .fc-btn:disabled { opacity:.6; cursor:wait; }
    .fc-note { padding:0 20px 16px; color:#667085; font-size:13px; line-height:1.6; }
    .fc-flash { padding:12px 16px; border-radius:10px; font-size:14px; line-height:1.55; }
    .fc-flash.ok { background:#ecfdf3; color:#05603a; border:1px solid #abefc6; }
    .fc-flash.err { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }
    .fc-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; padding:16px 20px; }
    .fc-stat { border:1px solid #eef0f2; border-radius:12px; padding:14px; background:#fcfcfd; }
    .fc-stat b { display:block; font-size:26px; color:#1f2937; }
    .fc-stat span { font-size:12px; color:#667085; }
    .fc-table-wrap { overflow-x:auto; padding:6px 20px 18px; }
    .fc-table { width:100%; border-collapse:collapse; font-size:13px; min-width:900px; }
    .fc-table th { text-align:left; padding:10px; background:#f8fafb; color:#667085; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
    .fc-table td { padding:10px; border-top:1px solid #eef0f2; color:#344054; vertical-align:top; }
    .fc-table tr.is-alert td { background:#fff8f6; }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:800; white-space:nowrap; }
    .pill.alert { background:#fee4e2; color:#b42318; } .pill.ok { background:#dcfae6; color:#067647; } .pill.insufficient { background:#f2f4f7; color:#475467; }
    .pill.high { background:#dcfae6; color:#067647; } .pill.medium { background:#fef0c7; color:#93370d; } .pill.low { background:#f2f4f7; color:#475467; }
    .pill.ai { background:#eef4ff; color:#1d4ed8; } .pill.rules { background:#f2f4f7; color:#475467; } .pill.done { background:#ecfdf3; color:#067647; }
    .fc-resolve { border:1px solid #d0d5dd; background:#fff; border-radius:7px; padding:5px 9px; font:inherit; font-size:11px; font-weight:700; cursor:pointer; color:#344054; }
    .fc-chart-tools { display:flex; gap:8px; align-items:center; padding:12px 20px 0; flex-wrap:wrap; font-size:13px; color:#475467; }
    .fc-chart-tools select { padding:7px 10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:13px; }
    .fc-chart-box { position:relative; height:300px; padding:8px 20px 18px; }
    .fc-toggle-btn { display:flex; align-items:center; gap:12px; width:100% !important; margin:0 !important; padding:16px 20px !important; border:0 !important; border-radius:0 !important; background:#fff !important; box-shadow:none !important; font:inherit; color:#1f2937; text-align:left; cursor:pointer; }
    .fc-toggle-btn:hover, .fc-toggle-btn:focus-visible { background:#fffaf4 !important; outline:none; }
    .fc-toggle-title { font-size:17px; font-weight:700; display:inline-flex; align-items:center; gap:8px; white-space:nowrap; }
    .fc-toggle-btn small { flex:1; color:#667085; font-size:13px; text-align:right; }
    .fc-toggle-pill { flex:none; font-size:12px; font-weight:700; color:#fff; background:#ea7a00; border-radius:999px; padding:5px 14px; }
    .fc-toggle-btn[aria-expanded="true"] { border-bottom:1px solid #eef0f2 !important; }
    .fc-toggle-body[hidden] { display:none !important; }
    .fc-view-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 -6px; }
    .fc-view-tab { display:inline-flex; align-items:center; gap:8px; width:auto !important; margin:0 !important; padding:11px 20px !important; border:1px solid rgba(255,255,255,.7) !important; border-radius:12px !important; background:rgba(255,255,255,.75) !important; color:#9a4a00 !important; font:inherit; font-size:14px; font-weight:800; cursor:pointer; box-shadow:0 4px 12px rgba(120,60,0,.08); }
    .fc-view-tab:hover { background:#fff !important; }
    .fc-view-tab.active { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%) !important; color:#fff !important; border-color:transparent !important; box-shadow:0 6px 16px rgba(234,122,0,.3); }
    .fc-view-tab:disabled { opacity:.55; cursor:not-allowed; }
    .fc-view[hidden] { display:none !important; }
    @media (max-width:700px) { .fc-toggle-btn small { display:none; } }
    .fc-empty { padding:22px 20px; color:#667085; }
    .fc-ov { display:flex; flex-wrap:wrap; gap:8px; padding:14px 20px 18px; }
    .fc-ov a { text-decoration:none; border:1px solid #eef0f2; border-radius:10px; padding:8px 12px; font-size:13px; color:#344054; background:#fff; }
    .fc-ov a.on { border-color:#ff8c00; box-shadow:0 0 0 2px rgba(255,140,0,.18); }
    .fc-ov b.al { color:#b42318; }
    .fc-sum { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; padding:18px 20px 4px; }
    .fc-sum-tile { border-radius:12px; padding:14px 16px; background:#f8fafb; border:1px solid #eef0f2; }
    .fc-sum-tile b { display:block; font-size:28px; line-height:1.1; color:#1f2937; }
    .fc-sum-tile span { font-size:12px; color:#667085; }
    .fc-sum-tile.red { background:#fef3f2; border-color:#fecdca; } .fc-sum-tile.red b { color:#b42318; }
    .fc-sub { margin:18px 20px 10px; font-size:14px; color:#344054; }
    .fc-need { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:12px; padding:0 20px; }
    .fc-need-card { border:1px solid #fecdca; border-left:4px solid #d92d20; border-radius:12px; padding:14px 16px; background:#fffbfa; display:flex; flex-direction:column; gap:8px; }
    .fc-need-card.done { border-color:#d0d5dd; border-left-color:#12b76a; background:#fcfcfd; opacity:.75; }
    .fc-need-top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
    .fc-need-name { font-size:16px; font-weight:800; color:#1f2937; }
    .fc-need-meta { font-size:12.5px; color:#667085; margin-top:2px; }
    .fc-need-order { text-align:center; background:#fff; border:1px solid #fecdca; border-radius:10px; padding:4px 12px; }
    .fc-need-order small { display:block; font-size:10px; color:#b42318; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .fc-need-order b { font-size:22px; color:#b42318; }
    .fc-need-text { margin:0; font-size:13px; color:#475467; line-height:1.5; }
    .fc-need-foot { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .fc-need-foot form { margin:0; }
    .fc-chips { display:flex; flex-wrap:wrap; gap:8px; padding:0 20px; }
    .fc-chip { display:inline-flex; gap:6px; align-items:center; padding:6px 11px; border-radius:999px; background:#ecfdf3; border:1px solid #abefc6; color:#067647; font-size:12.5px; }
    .fc-chip b { color:#05603a; }
    .fc-allgood { margin:16px 20px 0; padding:12px 14px; border-radius:10px; background:#ecfdf3; color:#067647; font-size:14px; }
    .fc-nodata { margin:14px 20px 0; color:#667085; font-size:12.5px; }
    .fc-more { margin:16px 20px 18px; }
    .fc-more summary { cursor:pointer; color:#c56a00; font-weight:700; font-size:13px; }
    .fc-more .fc-table-wrap { padding:10px 0 0; }
    .fc-how > summary { list-style:none; cursor:pointer; border-bottom:0; }
    .fc-how > summary::-webkit-details-marker { display:none; }
    .fc-how[open] > summary { border-bottom:1px solid #eef0f2; }
    @media (max-width:900px) { .fc-sum { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:520px) { .fc-sum { grid-template-columns:1fr; } }
    .fc-pick { margin:0 !important; font-size:13px; font-weight:700; color:#344054; }
    .fc-banner { display:flex; align-items:center; gap:14px; padding:14px 20px; border-radius:14px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; box-shadow:0 6px 18px rgba(120,60,0,.18); }
    .fc-banner > i { font-size:26px; }
    .fc-banner small { display:block; font-size:12px; opacity:.9; }
    .fc-banner b { font-size:22px; margin-right:10px; }
    .fc-banner span { font-size:13px; opacity:.9; }
    .fc-banner a { margin-left:auto; color:#fff; font-size:13px; font-weight:700; background:rgba(255,255,255,.2); padding:7px 12px; border-radius:8px; text-decoration:none; }
    .fc-need-brgy { font-size:11px; font-weight:800; color:#b42318; text-transform:uppercase; letter-spacing:.05em; }
    .fc-ov a b { margin-left:6px; font-weight:700; }
    .fc-ov a.al { border-color:#fecdca; background:#fffbfa; } .fc-ov a.gd { border-color:#abefc6; } .fc-ov a.nd { color:#667085; }
    .fc-ov a.on { background:#fff7ed; border-color:#ff8c00 !important; box-shadow:0 0 0 2px rgba(255,140,0,.25); font-weight:700; }
    .fc-method { padding:4px 20px 18px; color:#475467; font-size:13px; line-height:1.65; }
    .fc-method code { background:#f2f4f7; padding:1px 5px; border-radius:4px; }
    .fc-top { display:flex !important; flex-direction:row !important; align-items:center !important; gap:16px; flex-wrap:wrap; padding:16px 20px 4px; margin:0 !important; }
    .fc-top h3, .layout .fc-top h3 { margin:0; font-size:16px; color:#1f2937; background:none; padding:0; }
    .fc-top .fc-btn { width:auto !important; margin:0 0 0 auto !important; padding:8px 14px; }
    .fc-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:12px; color:#667085; }
    .fc-legend span { display:inline-flex; align-items:center; gap:5px; }
    i.d { display:inline-block; width:9px; height:9px; border-radius:50%; background:#d0d5dd; flex:none; }
    i.d.al { background:#d92d20; } i.d.gd { background:#12b76a; } i.d.lw { background:#f79009; }
    .fc-ov a.lw { border-color:#fedf89; background:#fffcf5; }
    .fc-ov a b.lw { background:#f79009; }
    .fc-chip.lw { background:#fffaeb; border-color:#fedf89; color:#93370d; } .fc-chip.lw b { color:#7a2e0e; }
    .fc-sum-tile.orange { background:#fffaeb; border-color:#fedf89; } .fc-sum-tile.orange b { color:#b54708; }
    .fc-sub-note { font-weight:400; color:#667085; font-size:12px; }
    .fc-ov { gap:8px; padding:12px 20px 6px; }
    .fc-ov a { display:inline-flex; align-items:center; gap:7px; padding:7px 12px; border-radius:999px; font-size:13px; }
    .fc-ov a.nd { color:#98a2b3; }
    .fc-ov a b { margin-left:0; background:#d92d20; color:#fff; border-radius:999px; font-size:11px; min-width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; padding:0 5px; }
    .fc-ov a.on { color:#9a4a00; }
    .fc-note { padding:6px 20px 16px; font-size:12.5px; margin:0; }
    .fc-sel h3, .layout .fc-sel h3 { font-size:20px; text-align:left; display:flex; align-items:center; justify-content:flex-start; gap:8px; width:auto; }
    .fc-card-head h3, .fc-top h3 { justify-content:flex-start !important; gap:8px; }
    .fc-chart-tools select { width:auto !important; min-width:220px; max-width:320px; margin:0 !important; }
    .fc-chart-msg { margin:12px 20px 0; padding:10px 14px; border-radius:10px; font-size:14px; font-weight:600; }
    .fc-chart-msg.green { background:#ecfdf3; color:#067647; } .fc-chart-msg.orange { background:#fffaeb; color:#b54708; } .fc-chart-msg.red { background:#fef3f2; color:#b42318; }
    .fc-chart-key { display:flex; flex-wrap:wrap; gap:16px; padding:12px 20px 0; font-size:12.5px; color:#475467; }
    .fc-chart-key span { display:inline-flex; align-items:center; gap:6px; }
    .fc-chart-key i.k { display:inline-block; width:22px; height:0; border-top:3px solid #ea7a00; }
    .fc-chart-key i.k.fc { border-top:3px dashed #3f9b46; } .fc-chart-key i.k.min { border-top:2px solid #d92d20; } .fc-chart-key i.k.today { width:0; height:14px; border-top:0; border-left:2px dashed #98a2b3; }
    .fc-sel small { display:block; margin-top:4px; }
    .fc-sel .fc-btn { padding:9px 16px; }
    .fc-ov a, .fc-ov a.al, .fc-ov a.lw, .fc-ov a.gd { background:#fff; border-color:#e4e7ec; color:#344054; }
    .fc-ov a:hover { border-color:#f5c48a; }
    .fc-ov a.on, .fc-ov a.on.al, .fc-ov a.on.lw, .fc-ov a.on.gd, .fc-ov a.on.nd { background:#fff4e6; border-color:#ea7a00 !important; color:#9a4a00; box-shadow:none; font-weight:800; }
    .cmp-pick { display:flex; align-items:center; gap:8px; font-size:14px; }
    .cmp-pick select { width:auto; margin:0; padding:7px 10px; border:1px solid #f5c48a; border-radius:8px; font:inherit; font-size:14px; font-weight:700; background:#fff; }
    .cmp-swap { border:1px solid #f5c48a; background:#fff; color:#c56a00; border-radius:8px; width:34px; height:34px; cursor:pointer; }
    .cmp-swap:hover { background:#fff7ed; }
    .cmp-dot { display:none; } /* sides are told apart by name; colours are kept for stock status only */
    .cmp-sum { display:grid; grid-template-columns:1fr 1fr; gap:14px; padding:16px 20px 0; }
    .cmp-col { border:1px solid #eef0f2; border-radius:12px; padding:12px 14px; background:#fcfcfd; }
    .cmp-col { border-top:3px solid #d0d5dd; }
    .cmp-col.st-al { border-top-color:#d92d20; } .cmp-col.st-lw { border-top-color:#f79009; } .cmp-col.st-gd { border-top-color:#12b76a; }
    .cmp-col h4 i.d { margin-right:4px; vertical-align:middle; }
    .cmp-st { display:inline-block; margin-left:6px; padding:2px 9px; border-radius:999px; font-size:11.5px; font-weight:700; vertical-align:middle; background:#f2f4f7; color:#475467; }
    .cmp-st.al { background:#fee4e2; color:#b42318; } .cmp-st.lw { background:#fef0c7; color:#93370d; } .cmp-st.gd { background:#dcfae6; color:#067647; }
    .cmp-col h4 { margin:0 0 10px; font-size:15px; color:#1f2937; } .cmp-col h4 small { font-weight:400; color:#98a2b3; font-size:12px; margin-left:4px; }
    .cmp-nums { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; }
    .cmp-nums div { text-align:center; } .cmp-nums b { display:block; font-size:22px; color:#1f2937; } .cmp-nums span { font-size:11.5px; color:#667085; line-height:1.3; display:block; }
    .cmp-nums .red b { color:#d92d20; } .cmp-nums .orange b { color:#dc6803; }
    .cmp-chart { height:260px; padding:0 20px; }
    .cmp-table { min-width:0 !important; } .cmp-table th:not(:first-child), .cmp-table td:not(:first-child) { width:36%; }
    .cmp-q { font-size:15px; margin-right:4px; }
    .cmp-t { display:inline-block; min-width:42px; text-align:center; padding:2px 10px; border-radius:999px; font-weight:700; font-size:12.5px; background:#f2f4f7; color:#475467; }
    .cmp-t.out { background:#fee4e2; color:#b42318; } .cmp-t.low { background:#fef0c7; color:#93370d; } .cmp-t.ok { background:#dcfae6; color:#067647; }
    .cmp-tools { margin:4px 20px 18px; } .cmp-tools summary { cursor:pointer; font-weight:700; color:#9a4a00; padding:8px 0; }
    @media (max-width:900px) { .cmp-sum { grid-template-columns:1fr; } .cmp-nums { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .fc-strip { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); margin:16px 20px 0; border:1px solid #eef0f2; border-radius:12px; background:#fcfcfd; }
    .fc-strip > div { display:flex; align-items:center; gap:12px; padding:12px 16px; border-left:1px solid #eef0f2; }
    .fc-strip > div:first-child { border-left:0; }
    .fc-strip b { font-size:26px; line-height:1; color:#1f2937; min-width:28px; }
    .fc-strip span { font-size:12px; line-height:1.35; color:#667085; }
    .fc-strip .red b { color:#b42318; } .fc-strip .orange b { color:#b54708; }
    .fc-panels { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:14px; padding:16px 20px 0; }
    .fc-panel { border:1px solid #eef0f2; border-radius:12px; padding:14px 16px; background:#fff; }
    .fc-panel-head { display:flex; justify-content:space-between; align-items:baseline; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
    .fc-panel-head b { font-size:15px; color:#1f2937; } .fc-panel-head small { font-size:12px; color:#98a2b3; }
    .fc-status { margin:0 0 10px; font-size:13px; font-weight:700; }
    .fc-status.green { color:#067647; } .fc-status.red { color:#b42318; } .fc-status.orange { color:#b54708; }
    .fc-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:6px 14px; }
    .fc-list span { display:flex; justify-content:space-between; gap:8px; padding:6px 10px; border-radius:8px; background:#f6fef9; font-size:13px; color:#344054; }
    .fc-list span b { color:#067647; }
    .fc-list.lw span { background:#fffaeb; } .fc-list.lw span b { color:#b54708; }
    .fc-list.out span { background:#fef3f2; } .fc-list.out span b { color:#b42318; font-size:12px; }
    .fc-out { color:#b42318; }
    .fc-hint { margin:10px 0 0; font-size:12px; color:#98a2b3; line-height:1.5; }
    @media (max-width:1000px) { .fc-panels { grid-template-columns:1fr; } .fc-strip { grid-template-columns:repeat(2,minmax(0,1fr)); } .fc-strip > div:nth-child(3) { border-left:0; } .fc-strip > div:nth-child(n+3) { border-top:1px solid #eef0f2; } }
    .fc-card:has(#fcDd) { overflow:visible; position:relative; z-index:20; }
    .fc-dd { position:relative; padding:12px 20px 6px; max-width:520px; }
    .fc-dd-btn { display:flex !important; align-items:center; gap:10px; width:100% !important; margin:0 !important; padding:12px 16px !important; border:1px solid #f5c48a !important; border-radius:12px !important; background:#fff !important; color:#1f2937 !important; font:inherit; font-size:15px; cursor:pointer; text-align:left; box-shadow:0 2px 8px rgba(120,60,0,.06); }
    .fc-dd-btn:hover, .fc-dd-btn[aria-expanded="true"] { border-color:#ea7a00 !important; box-shadow:0 0 0 3px rgba(234,122,0,.15); }
    .fc-dd-btn .fc-dd-name { font-weight:800; }
    .fc-dd-st { margin-left:auto; font-size:12.5px; color:#667085; white-space:nowrap; }
    .fc-dd-st.al { color:#b42318; font-weight:700; } .fc-dd-st.lw { color:#b54708; font-weight:600; } .fc-dd-st.gd { color:#067647; }
    .fc-dd-btn .fc-dd-st { margin-left:auto; }
    .fc-dd-caret { color:#c56a00; font-size:12px; transition:transform .15s; }
    .fc-dd-btn[aria-expanded="true"] .fc-dd-caret { transform:rotate(180deg); }
    .fc-dd-menu.fc-ov { position:absolute; left:20px; right:20px; top:calc(100% + 2px); z-index:40; display:block; padding:8px; background:#fff; border:1px solid #f0e6da; border-radius:12px; box-shadow:0 14px 32px rgba(16,24,40,.18); }
    .fc-dd-menu[hidden] { display:none !important; }
    .fc-dd-search { width:100% !important; margin:0 0 6px !important; padding:9px 12px !important; border:1px solid #e4e7ec; border-radius:8px; font:inherit; font-size:14px; box-sizing:border-box; }
    .fc-dd-list { max-height:300px; overflow-y:auto; }
    .fc-dd-menu.fc-ov a, .fc-dd-menu.fc-ov a.on { display:flex; align-items:center; gap:10px; padding:9px 10px; border:0 !important; border-radius:8px; background:transparent; color:#344054; font-size:14px; font-weight:500; text-decoration:none; box-shadow:none; }
    .fc-dd-menu.fc-ov a:hover { background:#fff7ed; }
    .fc-dd-menu.fc-ov a.on { background:#fff1df; color:#9a4a00; font-weight:700; }
    .fc-dd-menu.fc-ov a[hidden] { display:none; }
    .fc-dd-none { margin:6px 10px; font-size:13px; color:#98a2b3; }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-chart-line" style="color:var(--primary);margin-right:8px;"></i>Demand Forecast</h2>
      <p style="margin:4px 0 0;color:#667085;font-size:13px;">Forecast of each barangay's medical-supply demand for the next <?= FORECAST_HORIZON_DAYS ?> days, based on its equipment reports, with restocking recommendations.</p>
    </header>
    <main class="main-content">
      <div class="fc-page">
        <?php if ($flash): ?><div class="fc-flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

        <?php if ($is_staff): ?>
        <section class="fc-card"><p class="fc-note" style="padding-top:16px;"><i class="fa-solid fa-robot"></i> This forecast predicts how your barangay's <b>medical supplies</b> will be used in the next <?= FORECAST_HORIZON_DAYS ?> days, based on your equipment reports. It updates automatically every time you submit a report.<?php if (!$barangays): ?><br><br><b>No forecast yet:</b> submit an equipment report first. Forecasts need at least <?= FORECAST_MIN_POINTS ?> reports over a day or more. <a href="user_report.php">Submit Report</a><?php endif; ?></p></section>
        <?php else: ?>
        <section class="fc-card">
          <?php if (!$barangays): ?>
            <div class="fc-empty">No barangay has submitted an equipment report yet, so there is no history to learn from.</div>
          <?php else: ?>
          <form method="post" class="fc-top" id="fcForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="b" value="<?= $selected ?>">
            <h3>Choose a barangay</h3>
            <div class="fc-legend"><span><i class="d al"></i>Medical supplies low / restock / out of stock</span><span><i class="d lw"></i>Low tools &amp; equipment</span><span><i class="d gd"></i>All sufficient</span><span><i class="d nd"></i>Not enough data yet</span></div>
            <button class="fc-btn ghost" type="submit" name="generate" value="all" title="Re-run the forecast for every barangay"><i class="fa-solid fa-rotate"></i> Refresh all</button>
          </form>
          <?php $dd_rows = []; foreach ($barangays as $b => $n) { $o = $overview[$b] ?? null; $al = $o ? (int)$o['alerts'] : 0; $eq = forecast_equipment_status($b); $lw = count($eq['low']); $os = count($eq['out']);
              // Medical supplies use the stock rule too (0 = out, 1 to 15 = low), not only the forecast.
              $cd = fc_compare_data($b); $medbad = count(array_filter($cd['med'], function ($m) { return in_array($m['status'], ['out', 'low', 'alert'], true); }));
              $has = (bool)$cd['date'];
              $cls = ($al || $medbad || $os) ? 'al' : ($lw ? 'lw' : ($has ? 'gd' : 'nd'));
              $txt = $al ? $al . ' to restock' : ($medbad ? $medbad . ' medical suppl' . ($medbad === 1 ? 'y' : 'ies') . ' low' : ($os ? $os . ' tools out of stock' : ($lw ? 'Low tools & equipment' : ($has ? 'All sufficient' : 'Not enough data yet'))));
              $dd_rows[$b] = ['cls' => $cls, 'txt' => $txt, 'n' => $n]; }
            $cur = $dd_rows[$selected] ?? null; ?>
          <div class="fc-dd" id="fcDd">
            <button type="button" class="fc-dd-btn" id="fcDdBtn" aria-haspopup="listbox" aria-expanded="false">
              <i class="d <?= e($cur['cls'] ?? 'nd') ?>"></i>
              <span class="fc-dd-name"><?= e(barangay_label($selected)) ?></span>
              <span class="fc-dd-st"><?= e($cur['txt'] ?? '') ?></span>
              <i class="fa-solid fa-chevron-down fc-dd-caret"></i>
            </button>
            <div class="fc-dd-menu fc-ov" id="fcDdMenu" role="listbox" hidden>
              <input type="search" class="fc-dd-search" id="fcDdSearch" placeholder="Search barangay..." aria-label="Search barangay">
              <div class="fc-dd-list">
                <?php foreach ($dd_rows as $b => $r): ?>
                  <a href="forecast.php?b=<?= $b ?>" role="option" data-name="<?= e(strtolower(barangay_label($b)) . ' ' . $b) ?>" class="<?= $b === $selected ? 'on' : '' ?>"><i class="d <?= $r['cls'] ?>"></i><span class="fc-dd-name"><?= e(barangay_label($b)) ?></span><span class="fc-dd-st <?= $r['cls'] ?>"><?= e($r['txt']) ?></span></a>
                <?php endforeach; ?>
                <p class="fc-dd-none" hidden>No barangay found.</p>
              </div>
            </div>
          </div>
          <p class="fc-note"><i class="fa-solid fa-robot"></i> Updates automatically after every equipment report and once a day. Barangay staff are messaged when a medical supply needs restocking.</p>
          <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($selected && isset($barangays[$selected])): $sel_district = ''; foreach (south_caloocan_districts() as $dn => $di) if (in_array($selected, $di['barangays'], true)) $sel_district = $dn; ?>
        <div class="fc-view-tabs" role="tablist" aria-label="Forecast sections">
          <button type="button" class="fc-view-tab active" data-fc-view="summary" role="tab" aria-selected="true"><i class="fa-solid fa-location-dot"></i> <?= e(barangay_label($selected)) ?> overview</button>
          <button type="button" class="fc-view-tab" data-fc-view="chart" role="tab" aria-selected="false"<?= $chart ? '' : ' disabled title="Not enough report history yet to draw a forecast"' ?>><i class="fa-solid fa-chart-line"></i> Stock history and forecast</button>
          <?php if ($cmp): ?><button type="button" class="fc-view-tab" data-fc-view="compare" role="tab" aria-selected="false"><i class="fa-solid fa-code-compare"></i> Compare barangays</button><?php endif; ?>
        </div>
        <section class="fc-card fc-view" id="fcSummaryCard" data-fc-panel="summary">
          <div class="fc-card-head fc-sel"><div><h3><i class="fa-solid fa-location-dot" style="color:#ea7a00;"></i> <?= e(barangay_label($selected)) ?></h3>
            <small><?= $sel_district ? e($sel_district) . ' district · ' : '' ?><?= (int)$barangays[$selected] ?> report<?= $barangays[$selected] === 1 ? '' : 's' ?> · <?= $rows ? 'updated ' . e(date('M j, g:i A', strtotime($rows[0]['created_at']))) : 'no forecast yet' ?></small></div>
            <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="b" value="<?= $selected ?>"><button class="fc-btn" type="submit" name="generate" value="one"><i class="fa-solid fa-rotate"></i> Refresh</button></form></div>
          <?php if ($rows):
              $need = array_values(array_filter($rows, function ($r) { return $r['status'] === 'alert'; }));
              usort($need, function ($a, $b) { return ($a['is_resolved'] <=> $b['is_resolved']) ?: ((float)($a['days_to_stockout'] ?? 9999) <=> (float)($b['days_to_stockout'] ?? 9999)); });
              $okItems = array_filter($rows, function ($r) { return $r['status'] === 'ok'; });
              $noData = array_filter($rows, function ($r) { return $r['status'] === 'insufficient'; });
          ?>
            <?php $units_needed = array_sum(array_map(function ($r) { return $r['status'] === 'insufficient' ? 0 : (int)$r['predicted_demand']; }, $rows));
                  $units_order = array_sum(array_map(function ($r) { return ($r['is_resolved'] || $r['status'] === 'insufficient') ? 0 : (int)$r['recommended_order']; }, $rows)); ?>
            <div class="fc-strip">
              <div class="<?= count($alert_rows) ? 'red' : '' ?>"><b><?= count($alert_rows) ?></b><span>medical supplies<br>to restock</span></div>
              <div><b><?= $units_needed ?></b><span>units needed<br>in <?= FORECAST_HORIZON_DAYS ?> days</span></div>
              <div class="<?= $units_order ? 'red' : '' ?>"><b><?= $units_order ?></b><span>units<br>to order</span></div>
              <div class="<?= $out_equipment ? 'red' : ($low_equipment ? 'orange' : '') ?>"><b><?= count($out_equipment) + count($low_equipment) ?></b><span>tools &amp; equipment<br><?= $out_equipment ? count($out_equipment) . ' out of stock · ' : '' ?><?= count($low_equipment) ?> low</span></div>
            </div>

            <?php if ($need): ?>
              <h4 class="fc-sub"><i class="fa-solid fa-triangle-exclamation" style="color:#d92d20;"></i> Medical supplies to restock</h4>
              <div class="fc-need">
                <?php foreach ($need as $r): ?>
                  <div class="fc-need-card<?= $r['is_resolved'] ? ' done' : '' ?>">
                    <div class="fc-need-top">
                      <div><div class="fc-need-name"><?= e($r['item_name']) ?></div>
                        <div class="fc-need-meta"><?= (int)$r['current_stock'] === 0 ? '<b class="fc-out">Out of stock</b>' : 'Have <b>' . (int)$r['current_stock'] . '</b>' . ($r['days_to_stockout'] !== null ? ' · runs out in <b>~' . (int)ceil($r['days_to_stockout']) . ' day(s)</b>' : ' · below reorder level') ?></div></div>
                      <div class="fc-need-order"><small>Order</small><b><?= (int)$r['recommended_order'] ?></b></div>
                    </div>
                    <p class="fc-need-text"><?= e($r['recommendation']) ?></p>
                    <div class="fc-need-foot">
                      <span></span>
                      <?php if ($r['is_resolved']): ?><span class="pill done">✓ Resolved</span>
                      <?php elseif (!$is_staff): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="b" value="<?= $selected ?>"><button class="fc-resolve" name="resolve" value="<?= (int)$r['prediction_id'] ?>">Mark resolved</button></form><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="fc-panels">
              <div class="fc-panel">
                <div class="fc-panel-head"><b><i class="fa-solid fa-kit-medical" style="color:#3f9b46;"></i> Medical supplies</b><small>forecast · next <?= FORECAST_HORIZON_DAYS ?> days</small></div>
                <?php if ($need): ?><p class="fc-status red"><i class="fa-solid fa-circle-exclamation"></i> <?= count($alert_rows) ?: 'No' ?> open restock alert<?= count($alert_rows) === 1 ? '' : 's' ?> (see above)</p>
                <?php elseif ($okItems): ?><p class="fc-status green"><i class="fa-solid fa-circle-check"></i> All enough for the next <?= FORECAST_HORIZON_DAYS ?> days</p><?php endif; ?>
                <?php if ($okItems): ?><div class="fc-list"><?php foreach ($okItems as $r): ?><span><?= e($r['item_name']) ?><b><?= (int)$r['current_stock'] ?></b></span><?php endforeach; ?></div><?php endif; ?>
                <?php if ($noData): ?><p class="fc-hint"><i class="fa-solid fa-hourglass-half"></i> Not enough history yet for: <?= e(implode(', ', array_column($noData, 'item_name'))) ?> (needs <?= FORECAST_MIN_POINTS ?> reports over at least a day).</p><?php endif; ?>
              </div>
              <div class="fc-panel">
                <div class="fc-panel-head"><b><i class="fa-solid fa-toolbox" style="color:#dc6803;"></i> Tools &amp; equipment</b><small>minimum <?= FORECAST_REORDER_LEVEL ?> each</small></div>
                <?php if ($out_equipment): ?>
                  <p class="fc-status red"><i class="fa-solid fa-circle-xmark"></i> <?= count($out_equipment) ?> out of stock</p>
                  <div class="fc-list out"><?php foreach ($out_equipment as $label): ?><span><?= e($label) ?><b>Out of stock</b></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($low_equipment): ?>
                  <p class="fc-status orange"<?= $out_equipment ? ' style="margin-top:12px"' : '' ?>><i class="fa-solid fa-circle-exclamation"></i> <?= count($low_equipment) ?> low (1 to <?= FORECAST_REORDER_LEVEL ?>)</p>
                  <div class="fc-list lw"><?php foreach ($low_equipment as $label => $qty): ?><span><?= e($label) ?><b><?= $qty ?></b></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if (!$out_equipment && !$low_equipment): ?><p class="fc-status green"><i class="fa-solid fa-circle-check"></i> All above <?= FORECAST_REORDER_LEVEL ?></p><?php endif; ?>
                <p class="fc-hint">Reused, not used up, so these are checked against the minimum instead of being forecast.</p>
              </div>
            </div>

            <details class="fc-more"><summary>Show model details</summary>
            <div class="fc-table-wrap"><table class="fc-table">
              <thead><tr><th>Item</th><th>Status</th><th>Current stock</th><th>Usage / day</th><th>Predicted demand (<?= FORECAST_HORIZON_DAYS ?>d)</th><th>Runs out in</th><th>Order</th><th>Confidence</th><th>Model</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): $ins = $r['status'] === 'insufficient'; ?>
                <tr class="<?= $r['status'] === 'alert' && !$r['is_resolved'] ? 'is-alert' : '' ?>">
                  <td><strong><?= e($r['item_name']) ?></strong><br><small style="color:#98a2b3;"><?= (int)$r['data_points'] ?> data point(s)</small></td>
                  <td><span class="pill <?= e($r['status']) ?>"><?= $r['status'] === 'alert' ? 'Restock' : ($ins ? 'Not enough data' : 'Sufficient') ?></span></td>
                  <td><?= (int)$r['current_stock'] ?></td>
                  <td><?= $ins ? '-' : rtrim(rtrim(number_format((float)$r['daily_rate'], 2), '0'), '.') ?></td>
                  <td><b><?= $ins ? '-' : (int)$r['predicted_demand'] ?></b></td>
                  <td><?= $ins ? '-' : ($r['days_to_stockout'] !== null ? '~' . (int)ceil($r['days_to_stockout']) . ' day(s)' : 'No usage seen') ?></td>
                  <td><b><?= (int)$r['recommended_order'] ?></b></td>
                  <td><?= $ins ? '-' : '<span class="pill ' . e($r['confidence']) . '">' . ucfirst(e($r['confidence'])) . '</span><br><small style="color:#98a2b3;">R² ' . e($r['r_squared']) . '</small>' ?></td>
                  <td><?= $ins ? '-' : 'slope ' . e($r['trend_slope']) . '/day' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            </details>
          <?php else: ?>
            <div class="fc-empty">No forecast has been generated for this barangay yet.</div>
          <?php endif; ?>
        </section>

        <?php if ($chart): ?>
        <section class="fc-card fc-view" id="fcChartCard" data-fc-panel="chart" hidden>
          <div class="fc-card-head"><h3><i class="fa-solid fa-chart-line" style="color:#3f9b46;"></i> Stock history and forecast · <?= e(barangay_label($selected)) ?></h3><small>What was reported, and what the model expects for the next <?= FORECAST_HORIZON_DAYS ?> days</small></div>
          <div>
          <div class="fc-chart-tools">Item: <select id="fcItem"><?php foreach ($chart as $k => $c): ?><option value="<?= e($k) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <p class="fc-chart-msg" id="fcMsg"></p>
          <div class="fc-chart-key"><span><i class="k rep"></i>Reported stock</span><span><i class="k fc"></i>Forecast</span><span><i class="k min"></i>Minimum (<?= FORECAST_REORDER_LEVEL ?>)</span><span><i class="k today"></i>Today</span></div>
          <div class="fc-chart-box"><canvas id="fcChart"></canvas></div>
          </div>
        </section>
        <?php endif; ?>
        <?php if ($cmp): $A = $cmp['A']; $B = $cmp['B']; $la = barangay_label($cmp['a']); $lb = barangay_label($cmp['b']);
          $st_txt = ['out' => 'Out of stock', 'alert' => 'Restock', 'low' => 'Low stock', 'ok' => 'Sufficient', 'okfew' => 'Sufficient', 'nodata' => 'Not reported'];
          $st_cls = ['out' => 'alert', 'alert' => 'alert', 'low' => 'medium', 'ok' => 'ok', 'okfew' => 'ok', 'nodata' => 'insufficient'];
          $tq = function ($q) { return $q === null ? ['', '-'] : ($q === 0 ? ['out', 'Out'] : ($q <= FORECAST_REORDER_LEVEL ? ['low', $q] : ['ok', $q])); }; ?>
        <section class="fc-card fc-view" id="fcCompareCard" data-fc-panel="compare" hidden>
          <div class="fc-card-head"><h3><i class="fa-solid fa-code-compare" style="color:#ea7a00;"></i> Compare barangays</h3>
            <div class="cmp-pick"><span class="cmp-dot a"></span><select id="cmpPickA" aria-label="First barangay"><?php foreach ($barangays as $bx => $nx): ?><option value="<?= $bx ?>"<?= $bx === $cmp['a'] ? ' selected' : '' ?>><?= e(barangay_label($bx)) ?></option><?php endforeach; ?></select> <span style="color:#98a2b3;font-size:13px;">vs</span> <span class="cmp-dot b"></span>
              <select id="cmpPick" aria-label="Barangay to compare with"><?php foreach ($barangays as $bx => $nx): if ($bx === $cmp['a']) continue; ?><option value="<?= $bx ?>"<?= $bx === $cmp['b'] ? ' selected' : '' ?>><?= e(barangay_label($bx)) ?></option><?php endforeach; ?></select> <button type="button" class="cmp-swap" id="cmpSwap" title="Swap the two barangays"><i class="fa-solid fa-right-left"></i></button></div></div>
          <div class="cmp-sum">
            <?php foreach ([['a', $la, $A, $cmp['a']], ['b', $lb, $B, $cmp['b']]] as [$k, $lbl, $D, $bn]): $ds = $dd_rows[$bn] ?? ['cls' => 'nd', 'txt' => '']; ?>
              <div class="cmp-col st-<?= e($ds['cls']) ?>"><h4><i class="d <?= e($ds['cls']) ?>"></i> <?= e($lbl) ?> <span class="cmp-st <?= e($ds['cls']) ?>"><?= e($ds['txt']) ?></span> <small><?= $D['date'] ? 'report of ' . e(date('M j, Y', strtotime($D['date']))) : 'no report yet' ?></small></h4>
                <div class="cmp-nums">
                  <div class="<?= $D['sum']['restock'] ? 'red' : '' ?>"><b><?= $D['sum']['restock'] ?></b><span>medical supplies out, low or to restock</span></div>
                  <div><b><?= $D['sum']['need'] ?></b><span>units needed in <?= FORECAST_HORIZON_DAYS ?> days</span></div>
                  <div class="<?= $D['sum']['order'] ? 'red' : '' ?>"><b><?= $D['sum']['order'] ?></b><span>units to order</span></div>
                  <div class="<?= $D['sum']['tools_out'] ? 'red' : ($D['sum']['tools_low'] ? 'orange' : '') ?>"><b><?= $D['sum']['tools_out'] + $D['sum']['tools_low'] ?></b><span>tools low or out</span></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <h4 class="fc-sub"><i class="fa-solid fa-kit-medical" style="color:#3f9b46;"></i> Medical supplies</h4>
          <div class="cmp-chart"><canvas id="cmpChart"></canvas></div>
          <p class="fc-hint" style="padding:6px 20px 0;text-align:center;">Bar colour = stock level: <b style="color:#3f9b46">green</b> above <?= FORECAST_REORDER_LEVEL ?> · <b style="color:#dc6803">orange</b> low (1 to <?= FORECAST_REORDER_LEVEL ?>) · <b style="color:#d92d20">red</b> out of stock (0)</p>
          <div class="fc-table-wrap"><table class="fc-table cmp-table">
            <thead><tr><th>Item</th><th><span class="cmp-dot a"></span><?= e($la) ?></th><th><span class="cmp-dot b"></span><?= e($lb) ?></th></tr></thead>
            <tbody>
            <?php foreach ($A['med'] as $k => $ma): $mb = $B['med'][$k]; ?>
              <tr><td><strong><?= e($ma['name']) ?></strong></td>
                <?php foreach ([$ma, $mb] as $m): ?>
                  <td><b class="cmp-q"><?= $m['qty'] === null ? '-' : $m['qty'] ?></b> <span class="pill <?= $st_cls[$m['status']] ?>"><?= $st_txt[$m['status']] ?></span><?php if ($m['status'] === 'low' || $m['status'] === 'okfew'): ?><?php if ($m['need'] === null): ?><br><small style="color:#98a2b3;">forecast needs more reports</small><?php endif; ?><?php endif; ?><?php if ($m['need'] !== null): ?><br><small style="color:#667085;"><?= $m['need'] ? 'needs about ' . $m['need'] . ' in ' . FORECAST_HORIZON_DAYS . ' days' : 'no usage seen yet' ?></small><?php endif; ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <details class="fc-more cmp-tools"><summary>Tools &amp; equipment (<?= count($A['tools']) ?> items)</summary>
            <div class="fc-table-wrap"><table class="fc-table cmp-table">
              <thead><tr><th>Item</th><th><span class="cmp-dot a"></span><?= e($la) ?></th><th><span class="cmp-dot b"></span><?= e($lb) ?></th></tr></thead>
              <tbody>
              <?php foreach ($A['tools'] as $k => $ta): [$ca, $va] = $tq($ta['qty']); [$cb, $vb] = $tq($B['tools'][$k]['qty']); ?>
                <tr><td><?= e($ta['name']) ?></td><td><span class="cmp-t <?= $ca ?>"><?= e((string)$va) ?></span></td><td><span class="cmp-t <?= $cb ?>"><?= e((string)$vb) ?></span></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <p class="fc-hint" style="padding:0 20px 14px;">Red = out of stock (0) · orange = low (1 to <?= FORECAST_REORDER_LEVEL ?>) · green = above <?= FORECAST_REORDER_LEVEL ?>.</p>
          </details>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <section class="fc-card fc-toggle">
          <button type="button" class="fc-toggle-btn" aria-expanded="false">
            <span class="fc-toggle-title"><i class="fa-solid fa-circle-info" style="color:#667085;"></i> How the forecast works</span>
            <small>The machine learning model behind the numbers</small>
            <span class="fc-toggle-pill">Show</span>
          </button>
          <div class="fc-toggle-body fc-method" hidden>
            <b>1. Data:</b> every equipment report the barangay submitted (quantity of each medical supply over time).<br>
            <b>2. Machine learning model:</b> a <b>linear regression</b> (least squares) fitted to quantity over time gives the stock trend and its fit (R²); the <b>average daily consumption</b> is the stock that went down between reports (restocks are not counted as demand). The higher of the two usage estimates is used when the trend is downward.<br>
            <b>3. Prediction:</b> predicted demand = usage per day × <?= FORECAST_HORIZON_DAYS ?> days; runs out in = current stock ÷ usage per day; order = predicted demand + reorder level (<?= FORECAST_REORDER_LEVEL ?>) + 1 − current stock.<br>
            <b>4. Recommendation:</b> the numbers are turned into a short restocking recommendation for each item. The numbers themselves always come from the model. Results are saved in the <code>ml_prediction</code> table.
          </div>
        </section>
      </div>
    </main>
  </div>
</div>
<script>
document.querySelectorAll('form').forEach(function (f) { f.addEventListener('submit', function (e) {
  var btn = e.submitter; if (!btn || btn.name !== 'generate') return;
  setTimeout(function () { document.querySelectorAll('.fc-btn').forEach(function (b) { b.disabled = true; }); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Forecasting…'; }, 0);
}); });
</script>
<?php if ($chart): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var D = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
  var sel = document.getElementById('fcItem'), chart = null;
  var MIN = <?= FORECAST_REORDER_LEVEL ?>, DAY = 86400000;
  function fmt(ms) { return new Date(ms).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }
  // Vertical dashed "Today" line.
  var todayLine = { id: 'todayLine', afterDatasetsDraw: function (c) {
    var t = c.options.plugins.todayLine.at, x = c.scales.x.getPixelForValue(t), a = c.chartArea, g = c.ctx;
    if (x < a.left || x > a.right) return;
    g.save(); g.strokeStyle = '#98a2b3'; g.lineWidth = 1.5; g.setLineDash([4, 4]);
    g.beginPath(); g.moveTo(x, a.top); g.lineTo(x, a.bottom); g.stroke();
    g.setLineDash([]); g.fillStyle = '#667085'; g.font = '600 11px sans-serif'; g.textAlign = 'center'; g.fillText('Today', x, a.top - 4); g.restore();
  } };
  function draw() {
    var d = D[sel.value]; if (!d) return;
    var msg = document.getElementById('fcMsg'); msg.className = 'fc-chart-msg ' + d.msg[0]; msg.textContent = d.name + ': ' + d.msg[1];
    var all = d.hist.concat(d.proj), xs = all.map(function (p) { return p.x; });
    var lo = Math.min.apply(null, xs) - DAY, hi = Math.max.apply(null, xs) + DAY;
    var top = Math.max(MIN, Math.max.apply(null, all.map(function (p) { return p.y; })));
    if (chart) chart.destroy();
    chart = new Chart(document.getElementById('fcChart'), {
      type: 'line',
      data: { datasets: [
        { label: 'Reported stock', data: d.hist, borderColor: '#ea7a00', backgroundColor: 'rgba(234,122,0,.10)', fill: 'origin', borderWidth: 3, pointRadius: 5, pointBackgroundColor: '#ea7a00', tension: 0 },
        { label: 'Forecast', data: d.proj, borderColor: '#3f9b46', borderDash: [7, 5], borderWidth: 3, pointRadius: d.proj.map(function (p, i) { return i ? 4 : 0; }), pointBackgroundColor: '#3f9b46', tension: 0 },
        { label: 'Minimum', data: [{ x: lo, y: MIN }, { x: hi, y: MIN }], borderColor: '#d92d20', borderWidth: 2, pointRadius: 0 }
      ] },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false, layout: { padding: { top: 16 } },
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
          legend: { display: false }, todayLine: { at: d.today },
          tooltip: { filter: function (i) { return i.datasetIndex !== 2; },
            callbacks: { title: function (i) { return fmt(i[0].parsed.x); }, label: function (i) { return (i.datasetIndex === 0 ? 'Reported: ' : 'Forecast: ') + Math.round(i.parsed.y); } } }
        },
        scales: {
          x: { type: 'linear', min: lo, max: hi, grid: { color: '#f2f4f7' }, ticks: { maxRotation: 0, autoSkip: false, callback: function (v) { return fmt(v); } },
            // One tick every 7 days counted from today, so the gaps are even.
            afterBuildTicks: function (ax) { var t = [], s = Math.max(1, Math.ceil((hi - lo) / DAY / 70)) * 7 * DAY; for (var v = d.today; v >= lo; v -= s) t.unshift({ value: v }); for (v = d.today + s; v <= hi; v += s) t.push({ value: v }); ax.ticks = t; } },
          y: { beginAtZero: true, suggestedMax: Math.ceil(top * 1.15), grid: { color: '#f2f4f7' }, title: { display: true, text: 'Quantity on hand' }, ticks: { precision: 0 } }
        }
      },
      plugins: [todayLine]
    });
  }
  sel.addEventListener('change', draw);
  window.fcDrawChart = function () { if (chart) chart.resize(); else draw(); };
  <?php $first_alert = array_values(array_filter($rows, function ($r) { return $r['status'] === 'alert' && !$r['is_resolved']; })); if ($first_alert): ?>sel.value = <?= json_encode($first_alert[0]['item_key']) ?>;<?php endif; ?>
})();
</script>
<?php endif; ?>
<script>
// Demand Forecast: "Stock history and forecast" and "How the forecast works" open and close when clicked.
document.addEventListener('click', function (e) {
  var b = e.target.closest('.fc-toggle-btn');
  if (!b) return;
  var card = b.parentNode, body = card.querySelector('.fc-toggle-body');
  var open = b.getAttribute('aria-expanded') !== 'true';
  b.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (body) body.hidden = !open;
  var pill = b.querySelector('.fc-toggle-pill');
  if (pill) pill.textContent = (open ? 'Hide' : 'Show') + (card.id === 'fcChartCard' ? ' chart' : '');
  // The chart is drawn the first time it is opened (a hidden canvas has no size).
  if (open && card.id === 'fcChartCard' && window.fcDrawChart) window.fcDrawChart();
});
</script>
<script>
// Demand Forecast: the barangay overview and the stock history chart are shown one at a time, chosen with the tabs.
(function () {
  var tabs = document.querySelectorAll('[data-fc-view]');
  if (!tabs.length) return;
  function show(view, keep) {
    var tab = document.querySelector('[data-fc-view="' + view + '"]');
    if (!tab || tab.disabled) view = 'summary';
    tabs.forEach(function (t) { var on = t.dataset.fcView === view; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.querySelectorAll('[data-fc-panel]').forEach(function (p) { p.hidden = p.dataset.fcPanel !== view; });
    if (view === 'chart' && window.fcDrawChart) window.fcDrawChart();
    // Choosing another barangay keeps the same tab open.
    var hash = view === 'summary' ? '' : '#' + view;
    document.querySelectorAll('.fc-ov a[href^="forecast.php"]').forEach(function (a) { a.href = a.href.split('#')[0] + hash; });
    if (!keep) history.replaceState(null, '', location.pathname + location.search + hash);
    if (view === 'compare' && window.fcDrawCompare) window.fcDrawCompare();
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.fcView); }); });
  if (location.hash === '#chart' || location.hash === '#compare') show(location.hash.slice(1), true);
})();
</script>
<script>
(function () {
  // "Choose a barangay" dropdown: open/close, search, close on outside click or Esc.
  var btn = document.getElementById('fcDdBtn'), menu = document.getElementById('fcDdMenu');
  if (!btn || !menu) return;
  var search = document.getElementById('fcDdSearch'), none = menu.querySelector('.fc-dd-none');
  function toggle(open) {
    menu.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { search.value = ''; filter(); search.focus({ preventScroll: true }); var on = menu.querySelector('a.on'), list = menu.querySelector('.fc-dd-list'); if (on) list.scrollTop = on.offsetTop - list.offsetTop - 40; }
  }
  function filter() {
    var t = search.value.trim().toLowerCase(), shown = 0;
    menu.querySelectorAll('.fc-dd-list a').forEach(function (a) { var ok = !t || a.dataset.name.indexOf(t) !== -1; a.hidden = !ok; if (ok) shown++; });
    none.hidden = shown > 0;
  }
  btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(menu.hidden); });
  search.addEventListener('input', filter);
  search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { var first = menu.querySelector('.fc-dd-list a:not([hidden])'); if (first) location.href = first.href; } });
  document.addEventListener('click', function (e) { if (!menu.hidden && !e.target.closest('#fcDd')) toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { toggle(false); btn.focus(); } });
})();
</script>
<?php if ($cmp): ?>
<script>
(function () {
  // Both barangays can be changed here; the first one also becomes the barangay chosen at the top of the page.
  var pick = document.getElementById('cmpPick'), pickA = document.getElementById('cmpPickA');
  function go(a, b) { location.href = 'forecast.php?b=' + a + '&c=' + b + '#compare'; }
  if (pick) pick.addEventListener('change', function () { go(pickA.value, pick.value); });
  if (pickA) pickA.addEventListener('change', function () { go(pickA.value, pickA.value === pick.value ? '' : pick.value); });
  var swap = document.getElementById('cmpSwap');
  if (swap) swap.addEventListener('click', function () { go(pick.value, pickA.value); });
  var A = <?= json_encode(array_values($cmp['A']['med']), JSON_UNESCAPED_UNICODE) ?>, B = <?= json_encode(array_values($cmp['B']['med']), JSON_UNESCAPED_UNICODE) ?>;
  var drawn = false;
  function draw() {
    if (drawn || !window.Chart) return; drawn = true;
    // Every bar uses the stock-status colours from the legend (red 0, orange 1-15, green above 15).
    // Left bar = first barangay, right bar = second (same order as the cards above).
    var MIN = <?= FORECAST_REORDER_LEVEL ?>;
    function col(q) { return q === null ? '#d0d5dd' : (q === 0 ? '#d92d20' : (q <= MIN ? '#f79009' : '#3f9b46')); }
    new Chart(document.getElementById('cmpChart'), { type: 'bar', data: { labels: A.map(function (m) { return m.name; }), datasets: [
        { label: <?= json_encode($la) ?> , data: A.map(function (m) { return m.qty; }), backgroundColor: A.map(function (m) { return col(m.qty); }), borderRadius: 4 },
        { label: <?= json_encode($lb) ?> , data: B.map(function (m) { return m.qty; }), backgroundColor: B.map(function (m) { return col(m.qty); }), borderRadius: 4 } ] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Stock on hand' } }, x: { grid: { display: false } } } } });
  }
  window.fcDrawCompare = function () {
    if (window.Chart) return draw();
    var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js'; s.onload = draw; document.head.appendChild(s);
  };
  if (location.hash === '#compare') window.fcDrawCompare();
})();
</script>
<?php endif; ?>
</body>
</html>