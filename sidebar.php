<?php
if (basename((string)($_SERVER["SCRIPT_FILENAME"] ?? "")) === basename(__FILE__)) { http_response_code(404); exit; } // only included by other pages
$sidebar_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$sidebar_is_admin = is_role_in(['head_admin', 'official', 'admin']);
$sidebar_is_head = is_role_in(['head_admin']);
$sidebar_user_logo = '';
$sidebar_user_fullname = '';
$sidebar_message_unread = 0;
if (!empty($_SESSION['user_id'])) {
    $sidebar_logo_stmt = $conn->prepare('SELECT profile_logo, fullname FROM users WHERE id=? LIMIT 1');
    if ($sidebar_logo_stmt) {
        $sidebar_logo_user_id = (int)$_SESSION['user_id'];
        $sidebar_logo_stmt->bind_param('i', $sidebar_logo_user_id);
        $sidebar_logo_stmt->execute();
        $sidebar_logo_row = $sidebar_logo_stmt->get_result()->fetch_assoc() ?: [];
        $sidebar_user_logo = (string)($sidebar_logo_row['profile_logo'] ?? '');
        $sidebar_user_fullname = (string)($sidebar_logo_row['fullname'] ?? '');
        $sidebar_logo_stmt->close();
    }
      if ($sidebar_is_admin) {
        $sidebar_message_stmt = $conn->query("SELECT COUNT(*) AS total FROM contact_messages WHERE sender_role = 'user' AND is_read = 0");
        if ($sidebar_message_stmt) $sidebar_message_unread = (int)$sidebar_message_stmt->fetch_assoc()['total'];
      } else {
        $sidebar_message_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM contact_messages WHERE user_id = ? AND sender_role IN ('admin', 'system') AND is_read = 0");
        if ($sidebar_message_stmt) {
          $sidebar_message_user_id = (int)$_SESSION['user_id'];
          $sidebar_message_stmt->bind_param('i', $sidebar_message_user_id);
          $sidebar_message_stmt->execute();
          $sidebar_message_unread = (int)$sidebar_message_stmt->get_result()->fetch_assoc()['total'];
          $sidebar_message_stmt->close();
        }
      }
}

// Open ML forecast restock alerts: badge on "Demand Forecast" for DRRMC officials and the City Head.
$sidebar_forecast_alerts = 0;
if (!empty($_SESSION['user_id']) && $sidebar_is_admin) {
    try { require_once __DIR__ . '/forecast_engine.php'; $sidebar_forecast_alerts = forecast_open_alert_count(); } catch (Throwable $e) { $sidebar_forecast_alerts = 0; }
}

// Active advertisements for the zoom popup on the barangay dashboard (login popup + clicking an ad in the side panel).
$sidebar_ads = null;
if (!empty($_SESSION['user_id']) && !$sidebar_is_admin && $sidebar_page === 'user_dashboard.php') {
    $sidebar_ads = [];
    $sidebar_ad_result = $conn->query("SELECT title, image_path FROM advertisements WHERE is_active = 1 ORDER BY created_at DESC, id DESC");
    if ($sidebar_ad_result) while ($ad_row = $sidebar_ad_result->fetch_assoc()) $sidebar_ads[] = ['title' => $ad_row['title'], 'image' => $ad_row['image_path']];
}

// $new_tab = open in its own browser tab. Each page gets one named tab: clicking the link again (or double-clicking)
// goes back to that same tab instead of opening another. Skipped when you are already on that page.
$sidebar_link = static function (string $page, string $label, string $icon, bool $new_tab = false) use ($sidebar_page): string {
    $active = $sidebar_page === $page ? ' class="active"' : '';
    $tab_name = 'drrmc_' . preg_replace('/\W+/', '_', basename($page, '.php'));
    $tab = ($new_tab && $sidebar_page !== $page) ? ' target="' . $tab_name . '" data-open-once="' . $tab_name . '" title="Opens in its own tab; click again to switch back to it"' : '';
    return '<a href="' . e($page) . '"' . $active . $tab . '>' . $icon . ' ' . e($label) . '</a>';
};
?>
<script>
// The logged-in person's current profile picture and name, read fresh from the database on every page load.
// The maps use it for the "you are here" pin (assets/south_caloocan_map.js); my_avatar.php keeps it up to date.
window.SC_ME = <?php echo json_encode(['name' => $sidebar_user_fullname, 'photo' => $sidebar_user_logo], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
</script>
<aside class="sidebar" data-shared-sidebar>
  <h3><?php if ($sidebar_user_logo): ?><img src="<?php echo e($sidebar_user_logo); ?>" alt="Barangay logo" style="width:22px;height:22px;object-fit:cover;border-radius:50%;vertical-align:middle;"> <?php else: ?>🛡️ <?php endif; ?><?php echo $sidebar_is_head ? 'City Head Admin' : ($sidebar_is_admin ? 'DRRM Office' : 'Brgy BDRRMC Portal'); ?></h3>
  <p><?php echo $sidebar_is_head ? 'City-Level Governance' : 'South Caloocan Division'; ?></p>
  <nav>
    <?php if ($sidebar_is_head): ?>
      <?php echo $sidebar_link('admin_dashboard.php', 'Readiness Dashboard', '📊'); ?>
      <?php echo $sidebar_link('admin_reports.php', 'Consolidated Reports', '📋'); ?>
      <?php echo $sidebar_link('admin_ai_analytics.php', 'Analytics & Forecast', '📊'); ?>
      <?php echo $sidebar_link('forecast.php', 'Demand Forecast' . ($sidebar_forecast_alerts ? ' (' . $sidebar_forecast_alerts . ')' : ''), '📈'); ?>
      <?php echo $sidebar_link('weather.php', 'Weather & Alerts', '🌦️'); ?>
      <?php echo $sidebar_link('admin_logs.php', 'Audit Logs', '🛡️'); ?>
      <?php echo $sidebar_link('admin_approvals.php', 'User Management', '👥'); ?>
    <?php elseif ($sidebar_is_admin): ?>
      <?php echo $sidebar_link('admin_dashboard.php', 'City Monitoring', '📊'); ?>
      <?php echo $sidebar_link('admin_reports.php', 'Equipment Reports', '📋'); ?>
      <?php echo $sidebar_link('forecast.php', 'Demand Forecast' . ($sidebar_forecast_alerts ? ' (' . $sidebar_forecast_alerts . ')' : ''), '📈'); ?>
      <?php echo $sidebar_link('weather.php', 'Weather & Alerts', '🌦️'); ?>
      <?php echo $sidebar_link('admin_resource_requests.php', 'Resource Req', '📦'); ?>
      <?php echo $sidebar_link('admin_training.php', 'Training & Drills', '📚', true); ?>
      <?php echo $sidebar_link('admin_advertisements.php', 'Advertisements', '📣', true); ?>
    <?php else: ?>
      <?php echo $sidebar_link('user_dashboard.php', 'View Inventory', '📊'); ?>
      <?php echo $sidebar_link('user_report.php', 'Submit Report', '📋'); ?>
      <?php echo $sidebar_link('weather.php', 'Weather & Alerts', '🌦️'); ?>
      <?php echo $sidebar_link('item_transactions.php', 'Audit Logs', '🛡️'); ?>
      <?php echo $sidebar_link('forecast.php', 'Demand Forecast', '📈'); ?>
      <?php echo $sidebar_link('user_resource_requests.php', 'Resource Requests', '📦'); ?>
      <?php echo $sidebar_link('training_request.php', 'Training & Drills', '📚', true); ?>
    <?php endif; ?>
  </nav>
  <div class="divider"></div>
  <nav style="margin-bottom:0;">
    <?php echo $sidebar_link('profile.php', $sidebar_is_admin ? 'User Profile' : 'My Profile', '👤'); ?>
    <?php echo $sidebar_link('security.php', 'System Settings', '⚙️'); ?>
    <?php if (!$sidebar_is_head): ?>
    <a href="messages.php" class="sidebar-message-link" aria-label="Messages" title="Messages"><span class="sidebar-message-icon" aria-hidden="true">✉</span><span class="sidebar-message-label">Messages</span><span class="sidebar-message-badge" id="chatBadge"<?php echo $sidebar_message_unread ? '' : ' style="display:none"'; ?>><?php echo $sidebar_message_unread > 99 ? '99+' : (int)$sidebar_message_unread; ?></span></a>
    <?php endif; ?>
    <a href="logout.php" id="sidebarLogout">🚪 Log Out</a>
  </nav>
</aside>
<style>
  /* Log-out confirmation dialog. Kept here (not style.css) so it works even when a page has an older cached stylesheet. */
  .logout-modal { display:none; position:fixed; inset:0; z-index:5000; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.55); }
  .logout-modal.open { display:flex; }
  .logout-dialog { width:min(400px,100%); background:#fff; border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.35); padding:26px 24px 20px; text-align:center; animation:logoutIn .16s ease-out; }
  @keyframes logoutIn { from { opacity:0; transform:translateY(8px) scale(.98); } to { opacity:1; transform:none; } }
  .logout-icon { width:56px; height:56px; margin:0 auto 12px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:26px; background:rgba(255,140,0,.14); }
  .logout-dialog h3 { margin:0 0 6px; padding:0; background:none; color:#1f2937; font-size:18px; }
  .logout-dialog p { margin:0 0 20px; color:#667085; font-size:14px; }
  .logout-actions { display:flex; gap:10px; justify-content:center; }
  .logout-actions button, .logout-actions a { flex:1; padding:10px 16px; border-radius:8px; font:inherit; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; text-align:center; }
  .logout-cancel { background:#fff; color:#344054; border:1px solid #d0d5dd; }
  .logout-cancel:hover { background:#f9fafb; }
  .logout-confirm { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff !important; border:0; }
  .logout-confirm:hover { filter:brightness(1.08); }
  @media (prefers-reduced-motion: reduce) { .logout-dialog { animation:none; } }
</style>
<div class="logout-modal" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" aria-describedby="logoutText">
  <div class="logout-dialog">
    <div class="logout-icon" aria-hidden="true">🚪</div>
    <h3 id="logoutTitle">Log out?</h3>
    <p id="logoutText">Are you sure you want to log out? You'll need to sign in again to continue.</p>
    <div class="logout-actions">
      <button type="button" class="logout-cancel" id="logoutCancel">Cancel</button>
      <a href="logout.php" class="logout-confirm" id="logoutConfirm">Yes, log out</a>
    </div>
  </div>
</div>
<script>
(function () {
  // Ask before logging out. Without JavaScript the link still works as a plain logout.
  var link = document.getElementById('sidebarLogout');
  var modal = document.getElementById('logoutModal');
  if (!link || !modal) return;
  var cancel = document.getElementById('logoutCancel');
  function open() { modal.classList.add('open'); cancel.focus(); }
  function close() { modal.classList.remove('open'); link.focus(); }
  link.addEventListener('click', function (e) {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    open();
  });
  cancel.addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('open')) close();
  });
})();
</script>
<script>
(function () {
  // Links marked data-open-once open in their own browser tab, but only ONE per page: if that tab is already open
  // (a double-click, or clicking the menu again later) we switch back to it and leave what is in it untouched.
  [].slice.call(document.querySelectorAll('a[data-open-once]')).forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; // browser's own new-tab shortcuts
      event.preventDefault();
      var url = link.href, name = link.getAttribute('data-open-once');
      // Already inside that page's own tab: just open it here.
      if (window.name === name) { window.location.href = url; return; }
      // Load the page in its named tab (reuses the tab if it is open, so no duplicates). Loading it - instead of only
      // calling focus(), which browsers ignore for other tabs - makes the browser switch to that tab every time.
      var tab = window.open(url, name);
      if (!tab) { window.location.href = url; return; } // pop-ups blocked: use this tab instead
      try { tab.focus(); } catch (err) {}
    });
  });
})();
</script>
<?php if (!$sidebar_is_head): ?>
<script>
(function () {
  // Live unread counter on the chat icon: updates every 10 seconds without a page reload.
  var badge = document.getElementById('chatBadge');
  if (!badge) return;
  function show(n) {
    if (n > 0) { badge.textContent = n > 99 ? '99+' : String(n); badge.style.display = ''; }
    else { badge.style.display = 'none'; }
  }
  function poll() {
    if (document.hidden) return;
    fetch('chat_summary.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        show(d.unread || 0);
        if (d.weather_alert && typeof window.wxShow === 'function') window.wxShow(d.weather_alert);
      })
      .catch(function () {});
  }
  poll();
  setInterval(poll, 10000);
})();
</script>
<?php endif; ?>
<?php if (!$sidebar_is_admin): ?>
<style>
  .wx-modal { display:none; position:fixed; inset:0; z-index:4600; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.6); }
  .wx-modal.open { display:flex; }
  .wx-dialog { width:min(640px,100%); max-height:90vh; overflow-y:auto; background:#fff; border-radius:16px; box-shadow:0 22px 55px rgba(0,0,0,.35); }
  .wx-head { display:flex; align-items:flex-start; gap:12px; padding:18px 20px; background:linear-gradient(135deg,#b42318 0%,#e8753e 60%,#f59e0b 100%); color:#fff; border-radius:16px 16px 0 0; }
  .wx-head .wx-icon { font-size:30px; line-height:1; }
  .wx-head h3 { margin:0; font-size:18px; color:#fff; background:none; padding:0; }
  .wx-head p { margin:4px 0 0; font-size:13px; opacity:.95; }
  .wx-close { margin-left:auto; width:32px; height:32px; flex:0 0 32px; border:0; border-radius:8px; background:rgba(255,255,255,.2); color:#fff; font-size:22px; line-height:1; cursor:pointer; }
  .wx-close:hover { background:rgba(255,255,255,.35); }
  .wx-body { padding:16px 20px 6px; }
  .wx-message { margin:0 0 12px; color:#344054; font-size:14px; line-height:1.55; }
  .wx-items { list-style:none; margin:0; padding:0; display:grid; gap:8px; }
  .wx-items li { display:grid; grid-template-columns:1fr auto; gap:2px 12px; padding:10px 12px; border:1px solid #eef0f2; border-left:4px solid #f59e0b; border-radius:8px; background:#fcfcfd; }
  .wx-items li.Critical { border-left-color:#d92d20; }
  .wx-items li.High { border-left-color:#f79009; }
  .wx-items li.Low { border-left-color:#98a2b3; }
  .wx-items strong { color:#1f2937; font-size:14px; }
  .wx-qty { text-align:right; font-size:13px; color:#1f2937; font-weight:700; white-space:nowrap; }
  .wx-qty small { display:block; font-weight:500; color:#667085; font-size:11px; }
  .wx-reason { grid-column:1 / -1; color:#667085; font-size:12px; }
  .wx-pill { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; background:#fef0c7; color:#93370d; vertical-align:middle; }
  .wx-pill.Critical { background:#fee4e2; color:#b42318; }
  .wx-meta { margin:12px 0 0; color:#98a2b3; font-size:11px; }
  .wx-actions { display:flex; justify-content:flex-end; gap:10px; padding:14px 20px 18px; flex-wrap:wrap; }
  .wx-actions a, .wx-actions button { border:0; border-radius:8px; padding:11px 18px; font:inherit; font-size:13px; font-weight:800; cursor:pointer; text-decoration:none; }
  .wx-ok { background:#f2f4f7; color:#475467; }
  .wx-go { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; }
</style>
<div class="wx-modal" id="wxModal" role="alertdialog" aria-modal="true" aria-labelledby="wxTitle">
  <div class="wx-dialog">
    <div class="wx-head"><span class="wx-icon" aria-hidden="true">&#9888;&#65039;</span><div><h3 id="wxTitle"></h3><p id="wxSummary"></p></div><button type="button" class="wx-close" id="wxClose" aria-label="Close">&times;</button></div>
    <div class="wx-body"><p class="wx-message" id="wxMessage"></p><ul class="wx-items" id="wxItems"></ul><p class="wx-meta" id="wxMeta"></p></div>
    <div class="wx-actions"><button type="button" class="wx-ok" id="wxOk">Got it</button><a class="wx-go" href="user_report.php">Update equipment report</a></div>
  </div>
</div>
<script>
(function () {
  // Weather-based restocking reminder (weather_engine.php): shown once per alert; closing it marks it seen.
  var TOKEN = '<?php echo e(csrf_token()); ?>';
  var modal = document.getElementById('wxModal'), shownId = null;
  function el(id) { return document.getElementById(id); }
  window.wxShow = function (a) {
    if (!a || !a.id || shownId === a.id) return;
    shownId = a.id;
    el('wxTitle').textContent = a.popup_title || 'Weather alert';
    el('wxSummary').textContent = a.weather_summary || '';
    el('wxMessage').textContent = a.popup_message || '';
    var list = el('wxItems');
    list.textContent = '';
    (a.items_to_stock || []).forEach(function (it) {
      var li = document.createElement('li');
      li.className = it.priority;
      var name = document.createElement('strong');
      name.textContent = it.item_name;
      var pill = document.createElement('span');
      pill.className = 'wx-pill ' + it.priority;
      pill.textContent = it.priority;
      name.appendChild(pill);
      var qty = document.createElement('span');
      qty.className = 'wx-qty';
      qty.textContent = 'Stock up to ' + it.recommended_stock + ' ' + it.unit_of_measure;
      var have = document.createElement('small');
      have.textContent = 'you have ' + it.current_stock;
      qty.appendChild(have);
      var reason = document.createElement('span');
      reason.className = 'wx-reason';
      reason.textContent = it.reason || '';
      li.appendChild(name); li.appendChild(qty); li.appendChild(reason);
      list.appendChild(li);
    });
    el('wxMeta').textContent = 'Issued ' + a.issued_at + ' · ' + (a.source === 'ai' ? 'AI recommendation' : 'Rule-based recommendation') + ' · confidence ' + Math.round((a.confidence || 0) * 100) + '%';
    modal.classList.add('open');
  };
  function dismiss() {
    modal.classList.remove('open');
    if (!shownId) return;
    var body = new URLSearchParams();
    body.set('csrf', TOKEN);
    body.set('id', shownId);
    fetch('weather_alert_seen.php', { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
  }
  el('wxClose').addEventListener('click', dismiss);
  el('wxOk').addEventListener('click', dismiss);
  modal.querySelector('.wx-go').addEventListener('click', dismiss);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) dismiss(); });
})();
</script>
<?php endif; ?>
<?php if ($sidebar_is_head): ?>
<style>
  .hq-modal { display:none; position:fixed; inset:0; z-index:4000; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.55); }
  .hq-modal.open { display:flex; }
  .hq-dialog { width:min(780px,100%); max-height:92vh; overflow-y:auto; background:#fff; border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.35); }
  .hq-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; background:var(--primary); color:#fff; border-radius:14px 14px 0 0; }
  .hq-head strong { font-size:16px; }
  .hq-count { font-size:12px; background:rgba(255,255,255,.2); padding:3px 10px; border-radius:999px; }
  .hq-body { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.1fr); gap:20px; padding:20px; }
  .hq-id { border:1px solid #e5e7eb; border-radius:10px; background:#f8fafb; display:flex; align-items:center; justify-content:center; min-height:200px; overflow:hidden; }
  .hq-id img { display:block; width:100%; max-height:340px; object-fit:contain; }
  .hq-id span { color:#98a2b3; font-size:13px; padding:20px; text-align:center; }
  .hq-details { margin:0; font-size:13px; }
  .hq-details div { display:flex; flex-direction:column; padding:7px 0; border-bottom:1px solid #eef0f2; }
  .hq-details dt { color:#667085; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
  .hq-details dd { margin:2px 0 0; color:#1f2937; font-weight:600; word-break:break-word; }
  .hq-fields { grid-column:1 / -1; display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .hq-fields label { display:block; font-size:12px; font-weight:700; color:#344054; margin-bottom:5px; }
  .hq-fields select, .hq-fields textarea { width:100%; padding:9px 10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:13px; }
  .hq-fields textarea { min-height:38px; resize:vertical; }
  .hq-fields .hq-wide { grid-column:1 / -1; }
  .hq-fields select:disabled { background:#f2f4f7; color:#98a2b3; }
  .hq-msg { grid-column:1 / -1; font-size:13px; color:#b42318; min-height:18px; }
  .hq-actions { display:flex; justify-content:flex-end; gap:10px; padding:0 20px 20px; flex-wrap:wrap; }
  .hq-actions button { border:0; border-radius:8px; padding:11px 22px; font-weight:800; font-size:13px; cursor:pointer; letter-spacing:.03em; }
  .hq-approve { background:#198754; color:#fff; }
  .hq-reject { background:#fff; color:#dc3545; border:1px solid #f0a4ad !important; }
  .hq-later { background:#f2f4f7; color:#475467; }
  .hq-rescan { margin-right:auto; background:#eef4ff; color:#1d4ed8; border:1px solid #c7d7fe !important; }
  .hq-msg.ok { color:#067647; }
  .hq-head-right { display:flex; align-items:center; gap:10px; }
  .hq-close { width:32px; height:32px; border:0; border-radius:8px; background:rgba(255,255,255,.18); color:#fff; font-size:22px; line-height:1; cursor:pointer; display:grid; place-items:center; padding:0; }
  .hq-close:hover, .hq-close:focus-visible { background:rgba(255,255,255,.32); outline:none; }
  .hq-brgy-auto { display:flex; align-items:center; justify-content:space-between; gap:10px; min-height:38px; padding:7px 10px; border:1px solid #abefc6; border-radius:8px; background:#ecfdf3; font-size:13px; color:#067647; }
  .hq-brgy-auto[hidden] { display:none; }
  .hq-brgy-auto i { font-style:normal; font-weight:800; }
  .hq-brgy-auto small { color:#475467; font-size:11px; }  .hq-actions button:disabled { opacity:.55; cursor:wait; }
  @media (max-width:640px) { .hq-body, .hq-fields { grid-template-columns:1fr; } }
</style>
<div class="hq-modal" id="hqModal" role="dialog" aria-modal="true" aria-labelledby="hqTitle">
  <div class="hq-dialog">
    <div class="hq-head"><strong id="hqTitle">New registration request</strong><span class="hq-head-right"><span class="hq-count" id="hqCount"></span><button type="button" class="hq-close" id="hqClose" aria-label="Close and review later" title="Close (review all later)">&times;</button></span></div>
    <div class="hq-body">
      <div class="hq-id" id="hqId"></div>
      <dl class="hq-details">
        <div><dt>Applicant</dt><dd id="hqName"></dd></div>
        <div><dt>Google email</dt><dd id="hqEmail"></dd></div>
        <div><dt>Detected role</dt><dd id="hqRoleLabel"></dd></div>
        <div><dt>Barangay read from ID</dt><dd id="hqBarangay"></dd></div>
        <div><dt>Contact number</dt><dd id="hqPhone"></dd></div>
        <div><dt>Address</dt><dd id="hqAddress"></dd></div>
        <div><dt>Submitted</dt><dd id="hqSubmitted"></dd></div>
      </dl>
      <div class="hq-fields">
        <div><label for="hqRole">Assign role on approval</label><select id="hqRole"><option value="user">Barangay Staff (User)</option><option value="admin">DRRMC Admin</option></select>
          <div class="hq-brgy-auto" id="hqRoleAuto" hidden><span><i aria-hidden="true">&#10003;</i> <strong id="hqRoleAutoText"></strong> <small>read from ID</small></span></div></div>
        <div><label for="hqBarangaySelect">Assign barangay</label><select id="hqBarangaySelect"></select>
          <div class="hq-brgy-auto" id="hqBrgyAuto" hidden><span><i aria-hidden="true">&#10003;</i> <strong id="hqBrgyAutoText"></strong> <small>read from ID</small></span></div></div>
        <div class="hq-wide"><label for="hqReason">Reason if rejecting (optional)</label><textarea id="hqReason" maxlength="300"></textarea></div>
      </div>
      <div class="hq-msg" id="hqMsg"></div>
    </div>
    <div class="hq-actions">
      <button type="button" class="hq-rescan" id="hqRescan" title="Read the ID again with AI and update the detected details">&#8635; RE-SCAN ID</button>
      <button type="button" class="hq-later" id="hqLater">Review later</button>
      <button type="button" class="hq-reject" id="hqReject">REJECT</button>
      <button type="button" class="hq-approve" id="hqApprove">APPROVE</button>
    </div>
  </div>
</div>
<script>
(function () {
  var TOKEN = '<?php echo e(csrf_token()); ?>';
  var modal = document.getElementById('hqModal');
  var queue = [], current = null, busy = false;
  var dismissed = [];
  try { dismissed = JSON.parse(sessionStorage.getItem('hqDismissed') || '[]'); } catch (err) { dismissed = []; }

  function el(id) { return document.getElementById(id); }
  function saveDismissed() { try { sessionStorage.setItem('hqDismissed', JSON.stringify(dismissed)); } catch (err) {} }

  // Barangay dropdown: pre-selected from the ID when the AI read it, otherwise the Head Admin assigns it.
  var brgySelect = el('hqBarangaySelect');
  (function () {
    var none = document.createElement('option');
    none.value = '';
    none.textContent = 'Select barangay';
    brgySelect.appendChild(none);
    for (var n = 1; n <= 188; n++) {
      var opt = document.createElement('option');
      opt.value = String(n);
      opt.textContent = 'Barangay ' + n;
      brgySelect.appendChild(opt);
    }
  })();
  // When the barangay was read from the ID it is fixed: shown as a locked line instead of a dropdown.
  var brgyLocked = false;
  function syncBarangay() {
    var cityLevel = el('hqRole').value === 'admin';
    var showLocked = brgyLocked && !cityLevel && current && current.barangay_number;
    brgySelect.disabled = cityLevel;
    if (cityLevel) brgySelect.value = '';
    else if (showLocked) brgySelect.value = current.barangay_number;
    brgySelect.hidden = !!showLocked;
    el('hqBrgyAuto').hidden = !showLocked;
    if (showLocked) el('hqBrgyAutoText').textContent = brgySelect.options[brgySelect.selectedIndex] ? brgySelect.options[brgySelect.selectedIndex].textContent : 'Barangay ' + current.barangay_number;
  }
  function render(a) {
    current = a;
    el('hqName').textContent = a.name;
    el('hqEmail').textContent = a.email;
    el('hqRoleLabel').textContent = a.role_label;
    el('hqBarangay').textContent = a.barangay;
    el('hqPhone').textContent = a.phone;
    el('hqAddress').textContent = a.address;
    el('hqSubmitted').textContent = a.submitted;
    el('hqRole').value = a.default_role;
    // Role read from the ID is fixed, like the barangay: shown as a locked line instead of the dropdown.
    el('hqRole').hidden = !!a.role_locked;
    el('hqRoleAuto').hidden = !a.role_locked;
    el('hqRoleAutoText').textContent = el('hqRole').options[el('hqRole').selectedIndex].textContent;
    brgySelect.value = a.barangay_number || '';
    brgyLocked = !!a.barangay_number;
    syncBarangay();
    el('hqReason').value = '';
    el('hqMsg').textContent = '';
    el('hqMsg').className = 'hq-msg';
    var box = el('hqId');
    box.textContent = '';
    if (a.id_url) {
      var img = document.createElement('img');
      img.alt = 'Applicant ID';
      img.src = a.id_url;
      box.appendChild(img);
    } else {
      var none = document.createElement('span');
      none.textContent = 'No ID image on file';
      box.appendChild(none);
    }
    el('hqCount').textContent = queue.length > 1 ? queue.length + ' pending' : '1 pending';
    modal.classList.add('open');
  }

  function next() {
    queue = queue.filter(function (a) { return dismissed.indexOf(a.id) === -1; });
    if (queue.length) { render(queue[0]); }
    else { current = null; modal.classList.remove('open'); }
  }

  function poll() {
    if (document.hidden || busy) return;
    fetch('pending_registrations.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (list) {
        queue = list.filter(function (a) { return dismissed.indexOf(a.id) === -1; });
        if (!current && queue.length) { render(queue[0]); }
        else if (current) { el('hqCount').textContent = queue.length > 1 ? queue.length + ' pending' : '1 pending'; }
      })
      .catch(function () {});
  }

  function decide(kind) {
    if (!current || busy) return;
    if (kind === 'approve' && el('hqRole').value === 'user' && !brgySelect.value) {
      el('hqMsg').textContent = 'Choose the barangay for this staff member before approving.';
      return;
    }
    busy = true;
    var buttons = modal.querySelectorAll('button');
    buttons.forEach(function (b) { b.disabled = true; });
    var body = new URLSearchParams();
    body.set('csrf', TOKEN);
    body.set('id', current.id);
    body.set('decision', kind);
    body.set('role', el('hqRole').value);
    body.set('barangay', brgySelect.value);
    body.set('reason', el('hqReason').value);
    fetch('approval_decision.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        buttons.forEach(function (b) { b.disabled = false; });
        if (!d.ok) {
          el('hqMsg').textContent = d.message || 'Unable to save the decision.';
          if (d.message && d.message.indexOf('already handled') !== -1) { dismissed.push(current.id); saveDismissed(); next(); }
          return;
        }
        dismissed.push(current.id); saveDismissed();
        var wasLast = queue.length <= 1;
        next();
        if (wasLast && /admin_dashboard/.test(window.location.pathname)) window.location.reload();
        tableStale = true;
        reloadIfStale();
      })
      .catch(function () {
        busy = false;
        buttons.forEach(function (b) { b.disabled = false; });
        el('hqMsg').textContent = 'Network error. Please try again.';
      });
  }

  // Re-scan: the server reads the stored ID with AI again and saves what it finds; then the card is refreshed from the queue.
  function rescan() {
    if (!current || busy) return;
    busy = true;
    var id = current.id, rescanBtn = el('hqRescan'), label = rescanBtn.innerHTML;
    var buttons = modal.querySelectorAll('button');
    buttons.forEach(function (b) { b.disabled = true; });
    rescanBtn.innerHTML = 'Reading ID&hellip;';
    el('hqMsg').className = 'hq-msg';
    el('hqMsg').textContent = '';
    var body = new URLSearchParams();
    body.set('csrf', TOKEN);
    body.set('id', id);
    fetch('rescan_id.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.message || 'Unable to re-scan this ID.');
        return fetch('pending_registrations.php', { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (list) {
            queue = list.filter(function (a) { return dismissed.indexOf(a.id) === -1; });
            var fresh = queue.filter(function (a) { return a.id === id; })[0];
            if (fresh) render(fresh);
            tableStale = true;
            el('hqMsg').className = 'hq-msg ok';
            el('hqMsg').textContent = d.message;
          });
      })
      .catch(function (err) {
        el('hqMsg').className = 'hq-msg';
        el('hqMsg').textContent = err && err.message ? err.message : 'Network error. Please try again.';
      })
      .then(function () {
        busy = false;
        rescanBtn.innerHTML = label;
        buttons.forEach(function (b) { b.disabled = false; });
      });
  }

  // Lets a page (e.g. the pending table on admin_approvals.php) open the popup for one applicant, even one set to "Review later".
  window.hqReview = function (id) {
    id = Number(id);
    dismissed = dismissed.filter(function (d) { return d !== id; });
    saveDismissed();
    fetch('pending_registrations.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (list) {
        var pick = list.filter(function (a) { return a.id === id; })[0];
        if (!pick) { window.location.reload(); return; } // already handled elsewhere
        queue = [pick].concat(list.filter(function (a) { return a.id !== id && dismissed.indexOf(a.id) === -1; }));
        render(pick);
      })
      .catch(function () {});
  };
  // On the approvals page the table must reflect a re-scan or decision once the popup closes.
  var onApprovals = /admin_approvals/.test(window.location.pathname), tableStale = false;
  function reloadIfStale() { if (onApprovals && tableStale && !modal.classList.contains('open')) window.location.reload(); }

  el('hqRescan').addEventListener('click', rescan);
  el('hqRole').addEventListener('change', syncBarangay);
  el('hqApprove').addEventListener('click', function () { decide('approve'); });
  el('hqReject').addEventListener('click', function () { decide('reject'); });
  el('hqLater').addEventListener('click', function () { if (current) { dismissed.push(current.id); saveDismissed(); } next(); reloadIfStale(); });
  // ✕ / Esc: put every waiting request on "review later" at once so the popup stops for this session.
  // Only a registration that arrives after this reopens it; pending ones stay reachable from User Management.
  function closeAll() {
    if (busy) return;
    queue.forEach(function (a) { if (dismissed.indexOf(a.id) === -1) dismissed.push(a.id); });
    if (current && dismissed.indexOf(current.id) === -1) dismissed.push(current.id);
    saveDismissed();
    queue = []; current = null;
    modal.classList.remove('open');
    reloadIfStale();
  }
  el('hqClose').addEventListener('click', closeAll);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) closeAll(); });
  poll();
  setInterval(poll, 6000);
})();
</script>
<?php endif; ?>
<?php if (!$sidebar_is_admin && $sidebar_ads !== null): ?>
<style>
  .sb-ad-modal { display:none; position:fixed; inset:0; z-index:4500; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.6); }
  .sb-ad-modal.open { display:flex; }
  .sb-ad-dialog { position:relative; width:min(560px,100%); max-height:92vh; display:flex; flex-direction:column; background:#fff; border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.35); overflow:hidden; animation:sbAdIn .18s ease-out; }
  @keyframes sbAdIn { from { opacity:0; transform:translateY(10px) scale(.98); } to { opacity:1; transform:none; } }
  .sb-ad-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; background:var(--primary); color:#fff; }
  .sb-ad-head strong { font-size:15px; }
  .sb-ad-count { margin-left:10px; padding:2px 10px; border-radius:999px; background:rgba(255,255,255,.2); font-size:12px; font-weight:700; }
  .sb-ad-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; padding:0 4px; }
  .sb-ad-stage { position:relative; background:#0f172a; display:flex; align-items:center; justify-content:center; min-height:200px; overflow:hidden; }
  .sb-ad-stage img { display:block; width:100%; max-height:60vh; object-fit:contain; }
  .sb-ad-arrow { position:absolute; top:50%; transform:translateY(-50%); width:38px; height:44px; border:0; border-radius:8px; background:rgba(255,255,255,.92); color:#1f2937; font-size:26px; line-height:1; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.3); }
  .sb-ad-arrow:hover { background:#fff; color:var(--primary); }
  .sb-ad-arrow.prev { left:10px; } .sb-ad-arrow.next { right:10px; }
  .sb-ad-foot { padding:14px 18px 16px; }
  .sb-ad-title { margin:0; font-size:15px; color:#1f2937; line-height:1.4; }
  .sb-ad-dots { display:flex; justify-content:center; gap:7px; margin-top:12px; }
  .sb-ad-dots button { width:8px; height:8px; padding:0; border:0; border-radius:50%; background:#cbd5e1; cursor:pointer; }
  .sb-ad-dots button.on { background:var(--primary); transform:scale(1.25); }
  .sb-ad-empty { padding:40px 24px; text-align:center; color:#667085; font-size:14px; }
</style>
<div class="sb-ad-modal" id="sbAdModal" aria-hidden="true">
  <div class="sb-ad-dialog" role="dialog" aria-modal="true" aria-labelledby="sbAdHeading">
    <div class="sb-ad-head">
      <span><strong id="sbAdHeading">📣 Advertisements</strong><?php if (count($sidebar_ads) > 1): ?><span class="sb-ad-count" id="sbAdCount"></span><?php endif; ?></span>
      <button type="button" class="sb-ad-close" id="sbAdClose" aria-label="Close">&times;</button>
    </div>
    <?php if ($sidebar_ads): ?>
      <div class="sb-ad-stage">
        <img id="sbAdImage" src="" alt="">
        <?php if (count($sidebar_ads) > 1): ?>
          <button type="button" class="sb-ad-arrow prev" id="sbAdPrev" aria-label="Previous advertisement">&lsaquo;</button>
          <button type="button" class="sb-ad-arrow next" id="sbAdNext" aria-label="Next advertisement">&rsaquo;</button>
        <?php endif; ?>
      </div>
      <div class="sb-ad-foot">
        <p class="sb-ad-title" id="sbAdTitle"></p>
        <?php if (count($sidebar_ads) > 1): ?><div class="sb-ad-dots" id="sbAdDots"></div><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="sb-ad-empty">No advertisements have been posted yet.</div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var ads = <?php echo json_encode($sidebar_ads, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
  var modal = document.getElementById('sbAdModal');
  if (!modal) return;
  var index = 0;
  window.drrmcOpenAd = function (i) { index = (i >= 0 && i < ads.length) ? i : 0; open(); }; // lets other page parts (e.g. the ads panel) open a specific ad
  var img = document.getElementById('sbAdImage'), title = document.getElementById('sbAdTitle'),
      count = document.getElementById('sbAdCount'), dots = document.getElementById('sbAdDots');

  function render() {
    if (!ads.length) return;
    img.src = ads[index].image; img.alt = ads[index].title; title.textContent = ads[index].title;
    if (count) count.textContent = (index + 1) + ' of ' + ads.length;
    if (dots) [].forEach.call(dots.children, function (d, i) { d.classList.toggle('on', i === index); });
  }
  function go(step) { if (ads.length < 2) return; index = (index + step + ads.length) % ads.length; render(); }
  function open() {
    modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
    render();
  }
  function close() { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }

  if (dots) ads.forEach(function (ad, i) {
    var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Show advertisement ' + (i + 1));
    b.addEventListener('click', function () { index = i; render(); }); dots.appendChild(b);
  });
  document.getElementById('sbAdClose').addEventListener('click', close);
  modal.addEventListener('click', function (e) {
    if (e.target !== modal) return;
    close();
  });
  var prev = document.getElementById('sbAdPrev'), next = document.getElementById('sbAdNext');
  if (prev) prev.addEventListener('click', function () { go(-1); });
  if (next) next.addEventListener('click', function () { go(1); });
<?php if ($sidebar_page === 'user_dashboard.php' && $sidebar_ads && empty($_SESSION['ad_popup_shown'])): $_SESSION['ad_popup_shown'] = 1; ?>
  // Welcome popup: once after every login (reset in login_user_session), not on page refreshes.
  setTimeout(open, 350);
<?php endif; ?>
  document.addEventListener('keydown', function (e) {
    if (!modal.classList.contains('open')) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') go(-1); else if (e.key === 'ArrowRight') go(1);
  });
})();
</script>
<?php endif; ?>
