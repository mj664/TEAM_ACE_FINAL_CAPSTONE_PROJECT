<?php
require_once 'init.php';
require_once __DIR__ . '/south_caloocan_map.php';
require_login();
require_role(['head_admin','official','admin']);

$admin_id = (int)$_SESSION['user_id'];
$is_head = is_role_in(['head_admin']); // Head Admin sees this dashboard read-only
$chat_error = '';
$open_chat_user_id = 0;
if ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Location: admin_dashboard.php'); exit; // no write actions for the Head Admin
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_admin_message'])) {
  $target_user_id = (int)($_POST['target_user_id'] ?? 0);
  $admin_message = trim($_POST['message'] ?? '');
  $open_chat_user_id = $target_user_id;
  if ($target_user_id > 0 && $admin_message !== '') {
    $reply_stmt = $conn->prepare("INSERT INTO contact_messages (user_id, sender_id, sender_role, message) VALUES (?, ?, 'admin', ?)");
    if ($reply_stmt) {
      $reply_stmt->bind_param('iis', $target_user_id, $admin_id, $admin_message);
      $reply_stmt->execute();
      $reply_stmt->close();
    }
  } else {
    $chat_error = 'Please select a user and enter a reply.';
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_contact_read'])) {
  $read_stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE sender_role = 'user' AND is_read = 0");
  if ($read_stmt) {
    $read_stmt->execute();
    $read_stmt->close();
  }
}
// Restocking reminders are fully automatic (restock_engine.php); there is no manual "Remind" action any more.
require_once __DIR__ . '/restock_engine.php';
restock_engine_trigger(false);

$unread_stmt = $conn->query("SELECT COUNT(*) AS total FROM contact_messages WHERE sender_role = 'user' AND is_read = 0");
$unread_messages = $unread_stmt ? (int)$unread_stmt->fetch_assoc()['total'] : 0;
$admin_chat_res = $conn->query("SELECT cm.user_id, cm.sender_role, IF(cm.deleted_at IS NULL, cm.message, 'This message was removed') AS message, cm.created_at, cm.is_read, u.fullname, u.barangay FROM contact_messages cm INNER JOIN users u ON u.id = cm.user_id ORDER BY cm.created_at ASC, cm.id ASC");
$admin_chats = [];
if ($admin_chat_res) {
  while ($chat_row = $admin_chat_res->fetch_assoc()) {
    $uid = (int)$chat_row['user_id'];
    if (!isset($admin_chats[$uid])) {
      $admin_chats[$uid] = [
        'fullname' => $chat_row['fullname'],
        'barangay' => $chat_row['barangay'],
        'messages' => [],
        'unread' => 0,
        'last_at' => $chat_row['created_at'],
        'preview' => $chat_row['message'],
      ];
    }
    $admin_chats[$uid]['messages'][] = $chat_row;
    $admin_chats[$uid]['last_at'] = $chat_row['created_at'];
    $admin_chats[$uid]['preview'] = $chat_row['message'];
    if ($chat_row['sender_role'] === 'user' && empty($chat_row['is_read'])) {
      $admin_chats[$uid]['unread']++;
    }
  }
  uasort($admin_chats, function ($a, $b) {
    return strcmp((string)$b['last_at'], (string)$a['last_at']);
  });
}

$barangay_map_status = build_barangay_stock_status($conn);
$barangay_low_alerts = array_values(array_filter($barangay_map_status, function ($b) {
  return ($b['level'] ?? '') === 'critical' || !empty($b['low_stock']);
}));
$barangay_warning_alerts = array_values(array_filter($barangay_map_status, function ($b) {
  return ($b['level'] ?? '') === 'warning';
}));
$barangay_reported_count = count(array_filter($barangay_map_status, function ($b) {
  return !empty($b['has_report']);
}));
$insight_alerts = array_merge($barangay_low_alerts, $barangay_warning_alerts);
// Open automatic-reminder cycles, so each alert can show when the last message went out and when the next one is due.
$restock_open = [];
$restock_result = $conn->query("SELECT barangay, send_count, TIMESTAMPDIFF(MINUTE, last_sent_at, NOW()) AS mins_since, TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS secs_since FROM restock_reminders WHERE resolved_at IS NULL");
if ($restock_result) while ($restock_row = $restock_result->fetch_assoc()) $restock_open[(int)$restock_row['barangay']] = $restock_row;
$restock_span = function ($minutes) {
  $minutes = max(0, (int)$minutes);
  return $minutes < 60 ? $minutes . ' min' : floor($minutes / 60) . 'h ' . ($minutes % 60) . 'm';
};
$pending_users = [];
$pend = $conn->query("SELECT id, fullname, barangay, position, created_at FROM users WHERE status='pending' ORDER BY created_at DESC");
if ($pend) {
  while ($p = $pend->fetch_assoc()) $pending_users[] = $p;
}
$city_stats = ['approved' => 0, 'failed_24h' => 0, 'events_24h' => 0];
if ($is_head) {
  $acct_res = $conn->query("SELECT SUM(status='approved') AS approved FROM users WHERE role NOT IN ('admin','head_admin','official')");
  if ($acct_res) $city_stats['approved'] = (int)($acct_res->fetch_assoc()['approved'] ?? 0);
  $audit_res = $conn->query("SELECT SUM(status='Failed') AS failed, COUNT(*) AS total FROM tbl_audit_log WHERE created_at >= NOW() - INTERVAL 1 DAY");
  if ($audit_res && ($audit_row = $audit_res->fetch_assoc())) {
    $city_stats['failed_24h'] = (int)($audit_row['failed'] ?? 0);
    $city_stats['events_24h'] = (int)($audit_row['total'] ?? 0);
  }
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?php echo e(asset_v('assets/style.css')); ?>">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .admin-message-float { position:fixed; left:24px; bottom:24px; width:48px; height:48px; display:flex; align-items:center; justify-content:center; background:var(--primary); color:#fff; border-radius:50%; font-size:22px; box-shadow:0 3px 10px rgba(0,0,0,.2); z-index:1001; }
    .admin-message-float:hover { background:var(--primary-dark); color:#fff; text-decoration:none; transform:translateY(-2px); }
    .admin-message-count { position:absolute; top:-5px; right:-5px; min-width:20px; height:20px; padding:0 5px; border-radius:10px; background:#e74c3c; color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; }
    .admin-chat-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; align-items:flex-end; justify-content:flex-start; padding:24px 24px 82px; }
    .admin-chat-modal.active { display:flex; }
    .admin-chat { width:min(480px, calc(100vw - 48px)); max-height:75vh; background:#fff; border-radius:8px; box-shadow:0 5px 20px rgba(0,0,0,.25); overflow:auto; }
    .admin-chat-header { display:flex; justify-content:space-between; align-items:center; background:var(--primary); color:#fff; padding:14px 16px; position:sticky; top:0; }
    .admin-chat-close { border:0; background:transparent; color:#fff; font-size:24px; cursor:pointer; }
    .admin-chat-user { padding:14px; display:none; }
    .admin-chat-user.active { display:block; }
    .admin-chat-inbox { display:none; }
    .admin-chat-inbox.active { display:block; }
    .admin-chat-name { display:block; width:100%; text-align:left; border:0; background:transparent; padding:12px 16px; cursor:pointer; border-bottom:1px solid #eee; color:var(--primary); font-weight:700; font-size:14px; }
    .admin-chat-name:hover { background:#f4faf6; text-decoration:underline; }
    .admin-chat-name small { display:block; color:#777; font-weight:500; margin-top:3px; font-size:12px; text-decoration:none; }
    .admin-chat-name .unread-dot { display:inline-block; min-width:18px; height:18px; line-height:18px; text-align:center; border-radius:9px; background:#e74c3c; color:#fff; font-size:11px; margin-left:6px; }
    .admin-chat-thread-title { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
    .admin-chat-back { border:0; background:transparent; color:var(--primary); cursor:pointer; font-size:13px; padding:0; }
    .admin-chat-message { padding:7px 9px; margin:6px 0; border-radius:6px; background:#f1f1f1; font-size:13px; }
    .admin-chat-message.user { background:#fff1ed; border-left:3px solid #e8753e; }
    .admin-chat-message.admin { background:#e1f1e8; border-left:3px solid var(--primary); }
    .admin-chat-message small { display:block; color:#777; margin-top:3px; }
    .admin-chat form { display:flex; gap:8px; margin-top:8px; }
    .admin-chat textarea { min-height:52px; resize:vertical; flex:1; }

    .admin-content-wrap {
      display:flex; gap:18px; padding: 0 20px 20px; align-items:flex-start;
      margin-top: 6px;
    }
    .inventory-panel {
      flex: 1.4; background: linear-gradient(180deg, #faf7f4 0%, #f7f4f2 100%);
      border:1px solid #eaded4; border-radius:12px; padding:18px 18px 14px;
      box-shadow: 0 10px 20px rgba(44, 43, 41, 0.04);
      min-height: 460px;
    }
    .inventory-header {
      display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px;
      padding: 2px 2px 8px;
    }
    .inventory-header .title { font-size: 13px; font-weight:800; color:#52615d; letter-spacing: .08em; text-transform: uppercase; }
    .inventory-header .last-updated { font-size:11px; color:#706a66; background:#f2eee9; border:1px solid #e6ded5; border-radius:999px; padding:6px 10px; }
    .inventory-table {
      width:100%; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; border:1px solid #eadfdb;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
    }
    .inventory-table th {
      text-align:left; font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:#766d67; background:#f9f0ea; padding:12px 12px; border-bottom:1px solid #eee1d9;
      font-weight:800;
    }
    .inventory-table td {
      padding: 13px 12px; font-size:12px; color:#323232; border-bottom:1px solid #f2eeeb; background:#fff;
    }
    .inventory-table tbody tr { transition: background .2s ease; }
    .inventory-table tbody tr:hover { background:#faf8f6; }
    .inventory-table tbody tr:last-child td { border-bottom:none; }
    .item-name {
      display:flex; align-items:center; gap:10px; font-weight:700; color:#2b2d2b;
    }
    .item-icon {
      width: 24px; height:24px; border-radius: 7px; display:flex; align-items:center; justify-content:center; font-size: 11px; color:#fff; background:linear-gradient(135deg, #d9b38b 0%, #c98e63 100%);
      box-shadow: inset 0 -1px 0 rgba(0,0,0,.08);
    }
    .status-pill {
      display:inline-block; padding:5px 8px; border-radius: 14px; font-size: 10px; font-weight: 800; letter-spacing:.04em; text-transform: uppercase;
    }
    .status-pill.green { background:#dff4ea; color:#0d7b52; }
    .status-pill.orange { background:#f7e3d2; color:#b55e21; }
    .status-pill.red { background:#f9dacc; color:#b83c2a; }
    .status-pill.gray { background:#eaedf0; color:#58616a; }
    .qty-cell {
      text-align:center; font-weight:700; color:#383838;
    }
    .submit-btn {
      margin-top: 14px; display:inline-flex; align-items:center; justify-content:center; background: linear-gradient(180deg, #0e6a4f 0%, #0b5a45 100%); color:#fff; border:none; border-radius:5px; padding: 10px 18px; font-weight:700; font-size:12px; cursor:pointer; box-shadow: 0 6px 12px rgba(11,90,69,.18);
    }
    .submit-btn:hover { filter: brightness(1.02); }
    .right-panel {
      width: 290px; background:#f9f8f6; border:1px solid #e8d8cd; border-radius:12px; padding: 16px 14px 12px; box-shadow: 0 10px 18px rgba(61, 57, 54, 0.04);
    }
    .panel-title {
      font-size: 12px; font-weight: 800; letter-spacing:.08em; text-transform: uppercase; color:#2d4c44; margin-bottom:10px;
    }
    .insight-box {
      background:#fff; border:1px solid #efe4dc; border-radius:10px; padding: 12px 11px 10px; margin-bottom: 12px;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }
    .insight-box h4 {
      font-size: 13px; color:#2a2a2a; margin:0 0 7px; font-weight:800;
    }
    .insight-box p {
      font-size: 12px; color:#5a615d; line-height:1.45; margin:0;
    }
    .alert-box {
      background: #fff; border:1px solid #efe4dc; border-radius:10px; padding: 11px 10px; margin-bottom: 10px;
    }
    .alert-box strong { font-size: 12px; color:#2b2f34; display:block; margin-bottom: 5px; }
    .alert-box .small { font-size: 11px; color:#535b58; line-height:1.45; }
    .risk-score {
      margin-top:9px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    }
    .score-tag {
      background:#f6d7d1; color:#a93a2d; border-radius:14px; padding:5px 8px; font-size:9px; font-weight:800; letter-spacing:.04em; text-transform: uppercase;
    }
    .score-tag.orange { background:#f9e5c9; color:#ad6519; }
    .score-tag.green { background:#dff1e8; color:#17714f; }
    .mini-btn {
      display:inline-block; background:#f07c4e; color:#fff; border:none; border-radius:5px; font-size:11px; font-weight:700; padding:8px 11px; margin-top:8px; cursor:pointer;
      box-shadow: 0 6px 10px rgba(240,124,78,.16);
    }
    .contact-box { background:#fff; border:1px solid #efe4dc; border-radius:10px; padding: 10px; }
    .contact-item { display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#3a403d; margin: 10px 0; }
    .contact-icon { width:18px; height:18px; border-radius:50%; background:#edf4ef; display:flex; align-items:center; justify-content:center; font-size:11px; }
    .contact-item strong { display:block; font-size:12px; }
    .contact-item span { color:#5d615e; }
    /* Header stays put; the dashboard content scrolls inside a viewport-high column. */
    .dash-column { flex:1; min-width:0; min-height:0; height:100vh; display:flex; flex-direction:column; }
    .dashboard-main-shell { flex:1; min-height:0; display:flex; overflow:hidden; }
    .dashboard-main-scroll { min-height:0; overflow-y:auto; scroll-behavior:smooth; scrollbar-color:#f0b060 rgba(255,255,255,.35); scrollbar-width:thin; }
    .dashboard-main-scroll::-webkit-scrollbar { width:10px; }
    .dashboard-main-scroll::-webkit-scrollbar-track { background:rgba(255,255,255,.35); border-radius:5px; }
    .dashboard-main-scroll::-webkit-scrollbar-thumb { background:#f0b060; border-radius:5px; }
    .dashboard-main-scroll::-webkit-scrollbar-thumb:hover { background:#ea7a00; }

    /* Floating AI Support Insights panel (bottom-right, collapsible). */
    .insight-float { position:fixed; right:24px; bottom:24px; z-index:950; width:320px; max-width:calc(100vw - 32px); }
    .insight-float .insight-card { max-height:min(560px, calc(100vh - 150px)); overflow-y:auto; box-shadow:0 16px 40px rgba(120,60,0,.25); border-radius:14px; background:rgba(255,255,255,.97); -webkit-backdrop-filter:blur(12px); backdrop-filter:blur(12px); }
    .insight-float .insight-card h4 { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .insight-collapse { border:0; background:#fff7ed; color:#9a4a00; width:28px; height:28px; border-radius:50%; font-size:16px; line-height:1; cursor:pointer; flex-shrink:0; }
    .insight-collapse:hover, .insight-collapse:focus { background:#ffedd5; outline:none; }
    .insight-bubble { display:none; align-items:center; gap:8px; margin-left:auto; padding:12px 18px; border:0; border-radius:999px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; font:inherit; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 10px 24px rgba(234,122,0,.35); }
    .insight-bubble:hover, .insight-bubble:focus { filter:brightness(1.08); outline:none; }
    .insight-bubble .count { background:#fff; color:#b91c1c; border-radius:999px; padding:1px 8px; font-size:12px; }
    .insight-float.collapsed { width:auto; }
    .insight-float.collapsed .insight-card { display:none; }
    .insight-float.collapsed .insight-bubble { display:inline-flex; }
    .insight-row { display:flex; flex-direction:column; gap:5px; padding:9px 10px; border:1px solid #f0e6da; border-radius:8px; background:#fff; }
    .insight-row:hover { background:#fff7ed; }
    .insight-row-top { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .insight-pill { font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; white-space:nowrap; }
    .insight-pill.critical { background:#fee2e2; color:#b91c1c; }
    .insight-pill.warning { background:#fef3c7; color:#92400e; }
    .insight-timer { font-size:11px; color:#667085; display:flex; justify-content:space-between; gap:6px; }
    .insight-timer.due { color:#b91c1c; font-weight:600; }
    .insight-bar { height:4px; border-radius:999px; background:#f2f4f7; overflow:hidden; }
    .insight-bar span { display:block; height:100%; background:linear-gradient(90deg,#ff8c00,#3f9b46); border-radius:999px; }
    @media (max-width: 1200px) {
      .dash-column { height:auto; }
      .dashboard-main-shell { overflow:visible; }
      .dashboard-main-scroll { overflow-y:visible; }
    }
    @media (max-width: 640px) { .insight-float { right:16px; bottom:16px; } }
    #map { height:clamp(360px, calc(100vh - 300px), 550px) !important; }
    @media (max-width: 980px) {
      .admin-shell { flex-direction:column; }
      .admin-sidebar { width:100%; border-right:none; border-bottom:1px solid #d7d7d7; }
      .admin-content-wrap { flex-direction:column; }
      .right-panel { width:100%; }
      #map { height:clamp(360px, 58vh, 550px) !important; }
      .dashboard-main-shell { overflow:visible; }
      .dashboard-main-scroll { overflow-y:visible; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div class="dash-column">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h2 style="margin:0;font-size:24px;">Welcome, <span style="color:var(--primary);"><?php echo e($_SESSION['user_name']); ?></span></h2>
        <p style="margin:4px 0 0 0;color:#666;font-size:13px;"><?php echo $is_head ? 'City Readiness Dashboard &middot; Read-only oversight of all South Caloocan barangays' : 'Caloocan City Disaster Risk Reduction Management Department'; ?></p>
      </div>
    </header>

    <div class="dashboard-main-shell">
      <main class="main-content dashboard-main-scroll" style="flex:1;">
        <?php if ($is_head): ?>
        <section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:0 0 16px;">
          <?php
            $head_cards = [
              ['Barangays reporting', $barangay_reported_count . ' / ' . count($barangay_map_status), 'Submitted an equipment report', '#2d7a5c'],
              ['Critical stock', count($barangay_low_alerts), 'Medical supplies at 5 or fewer', '#dc2626'],
              ['Low-stock warning', count($barangay_warning_alerts), 'Supplies at 15 or fewer', '#d97706'],
            ];
            foreach ($head_cards as $hc): ?>
            <div class="card" style="border-left:4px solid <?php echo $hc[3]; ?>;padding:14px 16px;">
              <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#667085;"><?php echo e($hc[0]); ?></div>
              <div style="font-size:28px;font-weight:800;color:<?php echo $hc[3]; ?>;line-height:1.2;"><?php echo e((string)$hc[1]); ?></div>
              <div style="font-size:12px;color:#667085;"><?php echo e($hc[2]); ?></div>
            </div>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>
        <section class="card live-map-card">
          <div class="live-map-head">
            <h3>Inventory Status Map</h3>
            <div class="view-toggle" role="tablist">
              <button type="button" class="active" data-map-view="map">Map</button>
              <button type="button" data-map-view="list">List</button>
            </div>
          </div>
          <div id="southCaloocanMap" role="application" aria-label="South Caloocan inventory status map">
            <div class="sc-map-shell">
              <div class="sc-map-pane" id="scMapPane">
                <div class="sc-map-frame">
                  <div class="sc-map-banner">MONITORING HUB - SOUTH CALOOCAN EOC</div>
                  <div id="map" style="width: 100%; border-radius: 8px;"></div>
                </div>
              </div>
              <div class="sc-list-pane" id="scListPane" hidden></div>
            </div>
          </div>
        </section>
        <?php require_once __DIR__ . '/expiry_lib.php'; $exp_city = exp_city_overview();
          // One chip per barangay (most urgent first, from exp_city_overview); clicking a chip shows that barangay's batches.
          $exp_detail = [];
          foreach ($exp_city as $ec) {
            $grp = [];
            foreach (exp_batches($ec['barangay']) as $bt) { // same item + same date = one row
              $k = $bt['item_name'] . '|' . (string)$bt['expiry_date'];
              if (!isset($grp[$k])) $grp[$k] = ['item' => $bt['item_name'], 'date' => $bt['expiry_date'], 'units' => 0, 'batches' => 0];
              $grp[$k]['units'] += (int)$bt['qty_left']; $grp[$k]['batches']++;
            }
            $rank = ['expired' => 0, 'soon' => 1, 'ok' => 2, 'unset' => 3];
            uasort($grp, function ($a, $b) use ($rank) { return [$rank[exp_status($a['date'])[0]], (string)$a['date'], $a['item']] <=> [$rank[exp_status($b['date'])[0]], (string)$b['date'], $b['item']]; });
            $exp_detail[$ec['barangay']] = array_values($grp);
            $exp_gone[$ec['barangay']] = exp_expired_open($ec['barangay']); // expired + removed, until the barangay restocks
          }
          $exp_first = $exp_city ? $exp_city[0]['barangay'] : null; ?>
        <section class="card exp-city">
          <div class="exp-city-head">
            <h3>🗓 Medical Supply Expiry</h3>
            <div class="exp-city-kpis">
              <span class="k x"><b><?php echo array_sum(array_column(exp_expired_open(), 'disposed_qty')); ?></b> units expired &middot; waiting for restock</span>
              <span class="k s"><b><?php echo array_sum(array_column($exp_city, 'soon')); ?></b> units expiring within 3 months</span>
              <span class="k n"><b><?php echo array_sum(array_column($exp_city, 'unset')); ?></b> units with no expiry date yet</span>
            </div>
          </div>
          <?php if (!$exp_city): ?><p class="exp-city-ok">No barangay has reported medical supplies yet.</p><?php else: ?>
          <?php // Dropdown grouped by status (most urgent first); each option shows its unit counts.
            $exp_groups = ['x' => '⛔ Has expired supplies', 's' => '🗓 Expiring within 3 months', 'n' => 'No expiry dates yet', 'g' => '✓ All good'];
            $exp_by = [];
            foreach ($exp_city as $ec) $exp_by[($ec['expired'] || !empty($ec['removed'])) ? 'x' : ($ec['soon'] ? 's' : ($ec['unset'] ? 'n' : 'g'))][] = $ec; ?>
          <div class="exp-pick">
            <label for="expBrgy">Barangay</label>
            <select id="expBrgy" aria-label="Choose a barangay">
              <?php foreach ($exp_groups as $gk => $gl): if (empty($exp_by[$gk])) continue; ?>
                <optgroup label="<?php echo e($gl . ' (' . count($exp_by[$gk]) . ')'); ?>">
                  <?php foreach ($exp_by[$gk] as $ec): ?>
                    <option value="<?php echo (int)$ec['barangay']; ?>"<?php echo $ec['barangay'] === $exp_first ? ' selected' : ''; ?>><?php
                      echo e($ec['label']) . ' — ' . e($gk === 'x' ? ((int)$ec['expired'] + (int)($ec['removed'] ?? 0)) . ' expired' . ($ec['soon'] ? ', ' . $ec['soon'] . ' expiring' : '') : ($gk === 's' ? $ec['soon'] . ' expiring' : ($gk === 'n' ? $ec['unset'] . ' units, no dates' : 'all good'))); ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <span class="exp-pick-note"><?php echo count($exp_city); ?> barangays &middot; the most urgent are listed first</span>
          </div>
          <?php foreach ($exp_city as $ec): $rows = $exp_detail[$ec['barangay']] ?? []; ?>
            <div class="exp-bpanel" data-b="<?php echo (int)$ec['barangay']; ?>"<?php echo $ec['barangay'] === $exp_first ? '' : ' hidden'; ?>>
              <div class="exp-bpanel-head"><strong><?php echo e($ec['label']); ?></strong>
                <span><?php echo array_sum(array_column($exp_gone[$ec['barangay']] ?? [], 'disposed_qty')); ?> expired, waiting for restock &middot; <?php echo (int)$ec['soon']; ?> expiring within 3 months &middot; <?php echo (int)$ec['unset']; ?> no date &middot; <?php echo (int)$ec['ok']; ?> good <small>(units)</small></span></div>
              <div style="overflow-x:auto;"><table class="exp-city-table">
                <thead><tr><th>Item</th><th class="num">Units</th><th>Expiry date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $rw): [$st, , $lbl] = exp_status($rw['date']); ?>
                  <tr>
                    <td><?php echo e($rw['item']); ?><?php if ($rw['batches'] > 1): ?> <small>(<?php echo (int)$rw['batches']; ?> batches)</small><?php endif; ?></td>
                    <td class="num"><span class="ec <?php echo ['expired' => 'x', 'soon' => 's', 'ok' => 'g', 'unset' => 'n'][$st]; ?>"><?php echo (int)$rw['units']; ?></span></td>
                    <td><?php echo $rw['date'] ? e(date('M j, Y', strtotime($rw['date']))) : '<span style="color:#98a2b3;">Not entered yet</span>'; ?></td>
                    <td><span class="exp-chip <?php echo ['expired' => 'x', 'soon' => 's', 'ok' => 'g', 'unset' => 'n'][$st]; ?>"><?php echo ['expired' => '⛔ ', 'soon' => '🗓 ', 'ok' => '✓ ', 'unset' => ''][$st]; ?><?php echo e($st === 'ok' ? 'Good' : $lbl); ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($exp_gone[$ec['barangay']] ?? [] as $gn): ?>
                  <tr class="exp-gone">
                    <td><?php echo e($gn['item_name']); ?><?php echo $gn['lot_no'] ? ' <small>(lot ' . e($gn['lot_no']) . ')</small>' : ''; ?></td>
                    <td class="num"><span class="ec x"><?php echo (int)$gn['disposed_qty']; ?></span></td>
                    <td><?php echo e(date('M j, Y', strtotime($gn['expiry_date']))); ?></td>
                    <td><span class="exp-chip x">⛔ Expired &middot; removed <?php echo e(date('M j', strtotime($gn['disposed_at']))); ?>, waiting for restock</span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table></div>
            </div>
          <?php endforeach; ?>
          <script>
          // Barangay dropdown: show the chosen barangay's batches (replaces the one shown before).
          document.getElementById('expBrgy').addEventListener('change', function () {
            var v = this.value;
            document.querySelectorAll('.exp-bpanel').forEach(function (p) { p.hidden = p.dataset.b !== v; });
          });
          </script>
          <?php endif; ?>
        </section>
        <style>
          .exp-city { margin-top:16px; }
          .exp-city-head { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; }
          .exp-city-head h3 { margin:0 !important; padding:0 !important; background:none !important; color:#1f2937 !important; font-size:17px; border:0 !important; box-shadow:none !important; width:auto !important; }
          .exp-city-kpis { display:flex; flex-wrap:wrap; gap:6px; }
          .exp-city-kpis .k { padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; }
          .exp-city-kpis .k b { font-size:14px; margin-right:2px; }
          .exp-city-kpis .x { background:#fef3f2; color:#b42318; } .exp-city-kpis .s { background:#fffaeb; color:#b54708; } .exp-city-kpis .n { background:#f2f4f7; color:#475467; }
          .exp-city-sub { margin:4px 0 10px; color:#667085; font-size:12.5px; }
          .exp-city-table { width:100%; border-collapse:collapse; font-size:13px; }
          .exp-city-table th, .exp-city-table td { padding:9px 10px; border-bottom:1px solid #eef0f2; text-align:left; vertical-align:middle; }
          .exp-city-table th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#667085; background:#f9fafb; }
          .exp-city-table .num { text-align:center; width:90px; }
          .exp-city-table td small { color:#667085; }
          .exp-city .ec { font-weight:800; } .exp-city .ec.x { color:#b42318; } .exp-city .ec.s { color:#b54708; } .exp-city .ec.z { color:#d0d5dd; font-weight:600; }
          .exp-chip { display:inline-block; margin:2px 4px 2px 0; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:700; }
          .exp-chip.x { background:#fee4e2; color:#b42318; } .exp-chip.s { background:#fef0c7; color:#b54708; }
          .exp-city-ok { margin:6px 0 0; color:#067647; font-weight:600; }
          .exp-city-table tr.exp-gone td { background:#fffbfa; color:#7a271a; }
          .exp-city-sub .lg { margin-left:8px; font-size:11.5px; white-space:nowrap; } .lg.x { color:#d92d20; } .lg.s { color:#dc6803; } .lg.n { color:#98a2b3; } .lg.g { color:#12b76a; }
          .exp-pick { display:flex; flex-wrap:wrap; align-items:center; gap:8px 10px; margin:0 0 12px; }
          .exp-pick label { font-size:12px; font-weight:800; color:#475467; text-transform:uppercase; letter-spacing:.04em; margin:0; }
          .exp-pick select { width:auto !important; min-width:300px; max-width:100%; margin:0; padding:8px 12px; border:1px solid #d0d5dd; border-radius:8px; background:#fff; font:inherit; font-size:13.5px; font-weight:600; color:#1f2937; cursor:pointer; }
          .exp-pick-note { font-size:12px; color:#98a2b3; }
          .exp-bchips { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 12px; }
          .exp-b { display:inline-flex; align-items:center; gap:5px; margin:0; padding:5px 10px; border:1px solid #eaecf0; border-left:4px solid #d0d5dd; border-radius:999px; background:#fff; color:#344054; font:inherit; font-size:12.5px; font-weight:600; cursor:pointer; width:auto; text-transform:none; letter-spacing:0; }
          .exp-b.x { border-left-color:#d92d20; } .exp-b.s { border-left-color:#f79009; } .exp-b.n { border-left-color:#98a2b3; } .exp-b.g { border-left-color:#12b76a; }
          .exp-b:hover { background:#f9fafb; } .exp-b.on { background:#1f2937; color:#fff; border-color:#1f2937; }
          .exp-b i { font-style:normal; padding:0 6px; border-radius:999px; font-size:11px; font-weight:800; }
          .exp-b i.x { background:#fee4e2; color:#b42318; } .exp-b i.s { background:#fef0c7; color:#b54708; } .exp-b i.n { background:#f2f4f7; color:#667085; font-weight:600; } .exp-b i.g { background:#d1fadf; color:#067647; }
          .exp-bpanel { border:1px solid #eaecf0; border-radius:12px; overflow:hidden; }
          .exp-bpanel-head { display:flex; flex-wrap:wrap; justify-content:space-between; gap:8px; padding:10px 12px; background:#f9fafb; border-bottom:1px solid #eaecf0; font-size:13px; color:#475467; }
          .exp-bpanel-head strong { color:#1f2937; font-size:14px; }
          .exp-chip.g { background:#d1fadf; color:#067647; } .exp-chip.n { background:#f2f4f7; color:#475467; } .exp-city .ec.g { color:#067647; } .exp-city .ec.n { color:#475467; font-weight:700; }
          .exp-nodate { margin-top:10px; padding:8px 12px; border:1px dashed #d0d5dd; border-radius:10px; background:#fcfcfd; }
          .exp-nodate summary { cursor:pointer; color:#475467; font-weight:700; font-size:13px; } .exp-nodate summary small { color:#98a2b3; font-weight:400; }
          .exp-nodate-list { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
          .exp-nodate-list span { padding:3px 10px; border:1px solid #eaecf0; border-radius:999px; background:#fff; font-size:12px; color:#475467; }
          .exp-nodate-list b { color:#1f2937; margin-left:2px; }
        </style>
      </main>

      <div class="insight-float" id="insightFloat">
        <?php
          $critical_count = count($barangay_low_alerts);
          $warning_count = count($barangay_warning_alerts);
          $insight_visible = 4;
          $restock_interval_secs = RESTOCK_INTERVAL_HOURS * 3600;
          // Soonest upcoming automatic reminder across all open cycles.
          $next_reminder_in = null;
          foreach ($restock_open as $cycle) {
            $left = RESTOCK_INTERVAL_HOURS * 60 - (int)$cycle['mins_since'];
            if ($next_reminder_in === null || $left < $next_reminder_in) $next_reminder_in = $left;
          }
        ?>
        <button type="button" class="insight-bubble" id="insightExpand" aria-controls="insightCard" aria-expanded="false">💡 AI Insights<?php if ($insight_alerts): ?> <span class="count"><?php echo count($insight_alerts); ?></span><?php endif; ?></button>
        <div class="insight-card" id="insightCard">
          <h4><span>💡 AI Support Insights</span><button type="button" class="insight-collapse" id="insightCollapse" aria-label="Minimize AI Support Insights" title="Minimize">&minus;</button></h4>
          <div class="insight-stats">
            <span class="insight-chip critical"><b><?php echo (int)$critical_count; ?></b> Critical</span>
            <span class="insight-chip warning"><b><?php echo (int)$warning_count; ?></b> Warning</span>
          </div>
          <?php if ($insight_alerts): ?>
            <div class="insight-list" id="insightList">
              <?php foreach ($insight_alerts as $index => $alert): ?>
                <?php
                  $is_critical = !empty($alert['low_items']);
                  $alert_items = $is_critical ? $alert['low_items'] : ($alert['warning_items'] ?? []);
                  $reminder = $restock_open[(int)$alert['number']] ?? null;
                  $tooltip = $alert['label'] . ': ' . restock_item_names($alert_items, 4)
                    . ($reminder ? ' | Auto-reminder #' . (int)$reminder['send_count'] . ' sent ' . $restock_span($reminder['mins_since']) . ' ago, next in ' . $restock_span(RESTOCK_INTERVAL_HOURS * 60 - (int)$reminder['mins_since']) : '');
                  $secs_left = $reminder ? $restock_interval_secs - (int)$reminder['secs_since'] : null;
                ?>
                <div class="insight-row live-alert-card<?php echo $index >= $insight_visible ? ' insight-extra' : ''; ?>" data-barangay="<?php echo (int)$alert['number']; ?>" data-focus-barangay="<?php echo (int)$alert['number']; ?>" title="<?php echo e($tooltip); ?>">
                  <div class="insight-row-top">
                    <span class="insight-name"><?php echo e($alert['label']); ?></span>
                    <span class="insight-pill <?php echo $is_critical ? 'critical' : 'warning'; ?>"><?php echo count($alert_items); ?> low</span>
                  </div>
                  <?php if ($reminder): ?>
                    <div class="insight-timer" data-secs-left="<?php echo (int)$secs_left; ?>" data-interval="<?php echo $restock_interval_secs; ?>">
                      <span>Reminder #<?php echo (int)$reminder['send_count']; ?> sent <?php echo e($restock_span($reminder['mins_since'])); ?> ago</span>
                      <span class="insight-countdown">next in <?php echo e($restock_span(max(0, (int)floor($secs_left / 60)))); ?></span>
                    </div>
                    <div class="insight-bar" aria-hidden="true"><span style="width:<?php echo max(0, min(100, round((int)$reminder['secs_since'] / $restock_interval_secs * 100))); ?>%"></span></div>
                  <?php else: ?>
                    <div class="insight-timer"><span><?php echo $is_critical ? 'First reminder is being sent' : 'Warning only &middot; no reminder cycle'; ?></span></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($insight_alerts) > $insight_visible): ?>
              <button type="button" class="insight-toggle" id="insightToggle" data-total="<?php echo count($insight_alerts); ?>">Show all (<?php echo count($insight_alerts); ?>)</button>
            <?php endif; ?>
            <div class="insight-foot">&#129302; Auto-reminders every <?php echo RESTOCK_INTERVAL_HOURS; ?>h to Messages until restocked &middot; <?php echo $next_reminder_in !== null ? 'next round in <span id="insightNextRound">' . e($restock_span($next_reminder_in)) . '</span>' : 'sent when a barangay turns critical'; ?></div>
          <?php else: ?>
            <p class="insight-empty">All reporting barangays are at safe stock levels.</p>
          <?php endif; ?>
        </div>
        <script>
          (function () {
            var float = document.getElementById('insightFloat');
            var toggle = document.getElementById('insightToggle'), list = document.getElementById('insightList');
            if (toggle && list) {
              toggle.addEventListener('click', function () {
                var open = list.classList.toggle('expanded');
                toggle.textContent = open ? 'Show less' : 'Show all (' + toggle.getAttribute('data-total') + ')';
              });
            }

            // Minimize to a bubble; the choice is remembered in this browser only.
            var expandBtn = document.getElementById('insightExpand'), collapseBtn = document.getElementById('insightCollapse');
            function setCollapsed(c) {
              float.classList.toggle('collapsed', c);
              expandBtn.setAttribute('aria-expanded', c ? 'false' : 'true');
              try { localStorage.setItem('insightCollapsed', c ? '1' : '0'); } catch (err) {}
            }
            try { if (localStorage.getItem('insightCollapsed') === '1') setCollapsed(true); } catch (err) {}
            collapseBtn.addEventListener('click', function () { setCollapsed(true); expandBtn.focus(); });
            expandBtn.addEventListener('click', function () { setCollapsed(false); collapseBtn.focus(); });

            // Live 7-hour countdown per barangay. At zero the reminder goes out on the engine's next once-a-minute tick.
            var started = Date.now();
            function span(s) {
              var m = Math.max(0, Math.floor(s / 60));
              return m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
            }
            function tick() {
              var elapsed = (Date.now() - started) / 1000, soonest = null;
              document.querySelectorAll('.insight-timer[data-secs-left]').forEach(function (t) {
                var left = parseInt(t.getAttribute('data-secs-left'), 10) - elapsed;
                var interval = parseInt(t.getAttribute('data-interval'), 10);
                var cd = t.querySelector('.insight-countdown');
                t.classList.toggle('due', left <= 0);
                if (cd) cd.textContent = left > 0 ? 'next in ' + span(left) : 'sending now…';
                var bar = t.parentNode.querySelector('.insight-bar span');
                if (bar) bar.style.width = Math.min(100, Math.max(0, (interval - left) / interval * 100)) + '%';
                if (soonest === null || left < soonest) soonest = left;
              });
              var next = document.getElementById('insightNextRound');
              if (next && soonest !== null) next.textContent = soonest > 0 ? span(soonest) : 'now';
            }
            tick();
            setInterval(tick, 30000);
          })();
        </script>
      </div>
    </div>
  </div>
</div>
<div class="admin-chat-modal" id="adminChatModal" onclick="closeAdminChat(event)">
  <div class="admin-chat" onclick="event.stopPropagation()">
    <div class="admin-chat-header">
      <strong>💬 User Messages</strong>
      <button type="button" class="admin-chat-close" onclick="closeAdminChat()" aria-label="Close messages">&times;</button>
    </div>
    <?php if ($chat_error): ?><div class="errors" style="margin:12px;"><?php echo e($chat_error); ?></div><?php endif; ?>
    <?php if (empty($admin_chats)): ?>
      <p style="padding:16px;color:#666;">No user messages yet.</p>
    <?php else: ?>
      <div class="admin-chat-inbox active" id="adminChatInbox">
        <?php foreach ($admin_chats as $chat_user_id => $chat): ?>
          <button type="button" class="admin-chat-name" onclick="openAdminChatUser(<?php echo (int)$chat_user_id; ?>)">
            <?php echo e($chat['fullname']); ?>
            <?php if (!empty($chat['unread'])): ?><span class="unread-dot"><?php echo (int)$chat['unread']; ?></span><?php endif; ?>
            <small><?php echo e($chat['barangay'] ?: 'Barangay not specified'); ?></small>
            <small><?php
              $preview = trim(preg_replace('/\s+/', ' ', (string)$chat['preview']));
              echo e(strlen($preview) > 60 ? substr($preview, 0, 57) . '...' : $preview);
            ?></small>
          </button>
        <?php endforeach; ?>
        <?php if ($unread_messages > 0): ?>
          <form method="post" style="padding:12px;">
            <input type="hidden" name="mark_contact_read" value="1">
            <button type="submit" class="btn btn-small" style="width:100%;">Mark Messages Read</button>
          </form>
        <?php endif; ?>
      </div>
      <?php foreach ($admin_chats as $chat_user_id => $chat): ?>
        <section class="admin-chat-user" id="adminChatUser-<?php echo (int)$chat_user_id; ?>" data-chat-user="<?php echo (int)$chat_user_id; ?>">
          <div class="admin-chat-thread-title">
            <button type="button" class="admin-chat-back" onclick="showAdminChatInbox()">&larr; All chats</button>
          </div>
          <strong><?php echo e($chat['fullname']); ?></strong>
          <?php foreach ($chat['messages'] as $chat_message): ?>
            <div class="admin-chat-message <?php echo $chat_message['sender_role'] === 'user' ? 'user' : 'admin'; ?>">
              <?php echo nl2br(e($chat_message['message'])); ?>
              <small><?php echo $chat_message['sender_role'] === 'user' ? 'User' : 'You'; ?> · <?php echo e($chat_message['created_at']); ?></small>
            </div>
          <?php endforeach; ?>
          <form method="post">
            <input type="hidden" name="send_admin_message" value="1">
            <input type="hidden" name="target_user_id" value="<?php echo (int)$chat_user_id; ?>">
            <textarea name="message" placeholder="Reply to <?php echo e($chat['fullname']); ?>" required></textarea>
            <button type="submit" class="btn btn-small">Reply</button>
          </form>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<script>
window.SOUTH_CALOOCAN_MAP = <?php echo json_encode([
  'lowStockThreshold' => 5,
  'center' => [14.6507, 120.9830],
  'zoom' => 14,
  'districts' => south_caloocan_districts_payload(),
  'geojson' => asset_v('assets/caloocan_barangays.json'),
  'barangays' => $barangay_map_status,
], JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo e(asset_v('assets/south_caloocan_map.js')); ?>"></script>
<script>
function openAdminChat(event) {
  if (event) event.preventDefault();
  document.getElementById('adminChatModal').classList.add('active');
  showAdminChatInbox();
}
function closeAdminChat(event) {
  if (event && event.target !== event.currentTarget) return;
  document.getElementById('adminChatModal').classList.remove('active');
}
function showAdminChatInbox() {
  var inbox = document.getElementById('adminChatInbox');
  if (inbox) inbox.classList.add('active');
  document.querySelectorAll('.admin-chat-user').forEach(function (el) {
    el.classList.remove('active');
  });
}
function openAdminChatUser(userId) {
  document.getElementById('adminChatModal').classList.add('active');
  var inbox = document.getElementById('adminChatInbox');
  if (inbox) inbox.classList.remove('active');
  document.querySelectorAll('.admin-chat-user').forEach(function (el) {
    el.classList.toggle('active', el.getAttribute('data-chat-user') === String(userId));
  });
}
<?php if ($open_chat_user_id > 0 || $chat_error): ?>
openAdminChatUser(<?php echo (int)$open_chat_user_id; ?>);
<?php endif; ?>
</script>
</body>
</html>
