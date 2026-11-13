<?php
require_once 'init.php';
require_login();
require_role(['head_admin']); // Audit oversight is a Head Admin function

$q = trim((string)($_GET['q'] ?? ''));
$status = $_GET['status'] ?? '';
if (!in_array($status, ['Success', 'Failed'], true)) $status = '';
$range = (int)($_GET['range'] ?? 7);
if (!in_array($range, [1, 7, 30, 90], true)) $range = 7;
$filters_active = $q !== '' || $status !== '' || $range !== 7;

$where = ['created_at >= NOW() - INTERVAL ? DAY'];
$types = 'i';
$params = [$range];
if ($q !== '') {
  $where[] = '(actor_label LIKE ? OR action LIKE ? OR target LIKE ? OR details LIKE ?)';
  $like = '%' . $q . '%';
  $types .= 'ssss';
  array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['Success', 'Failed'], true)) {
  $where[] = 'status = ?';
  $types .= 's';
  $params[] = $status;
}
$where_sql = implode(' AND ', $where);

$summary = ['total' => 0, 'failed' => 0, 'actors' => 0];
$summary_stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status='Failed') AS failed, COUNT(DISTINCT user_id) AS actors FROM tbl_audit_log WHERE $where_sql");
if ($summary_stmt) {
  $summary_stmt->bind_param($types, ...$params);
  $summary_stmt->execute();
  $summary_row = $summary_stmt->get_result()->fetch_assoc() ?: [];
  $summary = ['total' => (int)($summary_row['total'] ?? 0), 'failed' => (int)($summary_row['failed'] ?? 0), 'actors' => (int)($summary_row['actors'] ?? 0)];
  $summary_stmt->close();
}

$entries = [];
$log_stmt = $conn->prepare("SELECT created_at, actor_label, actor_role, action, target, details, status, ip_address FROM tbl_audit_log WHERE $where_sql ORDER BY id DESC LIMIT 300");
if ($log_stmt) {
  $log_stmt->bind_param($types, ...$params);
  $log_stmt->execute();
  $log_result = $log_stmt->get_result();
  while ($row = $log_result->fetch_assoc()) $entries[] = $row;
  $log_stmt->close();
}

function audit_type_class($entry) {
  if ($entry['status'] === 'Failed') return 'type-error';
  $sensitive = ['ACCOUNT_', 'PASSWORD_', 'TWO_FACTOR', 'SESSIONS_'];
  foreach ($sensitive as $prefix) {
    if (strpos($entry['action'], $prefix) === 0) return 'type-warning';
  }
  return 'type-info';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Audit Logs</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .logs-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .logs-table thead { background:#f9fafb; border-bottom:1px solid #e5e7eb; }
    .logs-table th { padding:12px 16px; text-align:left; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; }
    .logs-table td { padding:12px 16px; border-bottom:1px solid #e5e7eb; color:#374151; font-size:13px; vertical-align:top; }
    .logs-table tbody tr:hover { background:#f9fafb; }
    .log-type { display:inline-block; padding:4px 8px; border-radius:4px; font-size:10px; font-weight:700; white-space:nowrap; }
    .type-info { background:#dbeafe; color:#1d4ed8; }
    .type-warning { background:#fef3c7; color:#92400e; }
    .type-error { background:#fee2e2; color:#991b1b; }
    .log-sub { display:block; color:#98a2b3; font-size:11px; margin-top:2px; }
    /* flex-direction:row overrides the global "form { flex-direction:column }" that stacked these fields. */
    .filter-bar { display:flex; flex-direction:row; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap; padding:12px; background:rgba(255,255,255,.94); border:1px solid rgba(255,255,255,.7); border-radius:12px; box-shadow:0 4px 14px rgba(120,60,0,.1); }
    .filter-bar .filter-search { flex:1 1 280px; min-width:0; }
    .filter-bar select.filter-input { width:auto; flex:0 0 180px; }
    .filter-input { height:40px; padding:8px 12px; border:1px solid #d0d5dd; border-radius:8px; font-size:13px; background:#fff; margin:0; }
    .filter-input:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .filter-bar .btn { height:40px; padding:0 22px; border-radius:8px; flex:0 0 auto; }
    .filter-reset { height:40px; display:inline-flex; align-items:center; padding:0 14px; border:1px solid #d0d5dd; border-radius:8px; background:#fff; color:#344054; font-size:12px; font-weight:700; text-decoration:none; }
    .filter-reset:hover { background:#fff7ed; border-color:#ff8c00; color:#9a4a00; }
    .filter-result { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:0 0 16px; font-size:13px; color:#fff; text-shadow:0 1px 2px rgba(0,0,0,.25); }
    .filter-result b { font-weight:700; }
    .filter-chip { display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px; background:rgba(255,255,255,.92); color:#9a4a00; font-size:12px; font-weight:700; text-shadow:none; }
    @media (max-width:700px) { .filter-bar select.filter-input, .filter-bar .btn, .filter-reset { flex:1 1 100%; justify-content:center; } }
    .audit-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:20px; }
    .audit-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
    .audit-card b { display:block; font-size:26px; color:#1f2937; }
    .audit-card span { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#667085; }
    .logs-page-shell { flex:1; min-height:0; height:100vh; }
    .logs-scroll { min-height:0; overflow-y:auto; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    @media (max-width:1200px) {
      .logs-page-shell { height:auto; min-height:0; }
      .logs-scroll { overflow-y:visible; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="logs-page-shell" style="display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;"><h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-shield-halved" style="color:var(--primary);margin-right:8px;"></i>Audit Logs</h2><p style="margin:4px 0 0;color:#666;font-size:13px;">Read-only trail of sign-ins, account decisions, request reviews and inventory transactions across all barangays.</p></header>
    <main class="main-content logs-scroll" style="flex:1; padding:24px;">
      <div style="max-width:1200px; margin:0 auto;">
        <div class="audit-summary">
          <div class="audit-card"><b><?php echo $summary['total']; ?></b><span>Events (<?php echo $range === 1 ? '24 hours' : $range . ' days'; ?>)</span></div>
          <div class="audit-card"><b style="color:<?php echo $summary['failed'] ? '#dc2626' : '#059669'; ?>;"><?php echo $summary['failed']; ?></b><span>Failed / denied</span></div>
          <div class="audit-card"><b><?php echo $summary['actors']; ?></b><span>Distinct accounts</span></div>
        </div>

        <form method="get" action="admin_logs.php" class="filter-bar" role="search">
          <input type="search" name="q" class="filter-input filter-search" placeholder="Search user, action, target or details" aria-label="Search audit logs" value="<?php echo e($q); ?>">
          <select name="status" class="filter-input" aria-label="Outcome">
            <option value="">All outcomes</option>
            <option value="Success"<?php echo $status === 'Success' ? ' selected' : ''; ?>>Success</option>
            <option value="Failed"<?php echo $status === 'Failed' ? ' selected' : ''; ?>>Failed / denied</option>
          </select>
          <select name="range" class="filter-input" aria-label="Time range">
            <option value="1"<?php echo $range === 1 ? ' selected' : ''; ?>>Last 24 hours</option>
            <option value="7"<?php echo $range === 7 ? ' selected' : ''; ?>>Last 7 days</option>
            <option value="30"<?php echo $range === 30 ? ' selected' : ''; ?>>Last 30 days</option>
            <option value="90"<?php echo $range === 90 ? ' selected' : ''; ?>>Last 90 days</option>
          </select>
          <button class="btn btn-small" type="submit">Apply</button>
          <?php if ($filters_active): ?><a href="admin_logs.php" class="filter-reset">Reset</a><?php endif; ?>
        </form>
        <p class="filter-result" aria-live="polite">
          Showing <b><?php echo count($entries); ?></b> event<?php echo count($entries) === 1 ? '' : 's'; ?>
          <span class="filter-chip"><?php echo $range === 1 ? 'Last 24 hours' : 'Last ' . $range . ' days'; ?></span>
          <?php if ($status !== ''): ?><span class="filter-chip"><?php echo $status === 'Failed' ? 'Failed / denied' : 'Success'; ?></span><?php endif; ?>
          <?php if ($q !== ''): ?><span class="filter-chip">&ldquo;<?php echo e($q); ?>&rdquo;</span><?php endif; ?>
        </p>

        <table class="logs-table">
          <thead>
            <tr><th>Timestamp</th><th>User</th><th>Action</th><th>Target / Details</th><th>Outcome</th></tr>
          </thead>
          <tbody>
            <?php if (!$entries): ?>
              <tr><td colspan="5" style="text-align:center;color:#667085;padding:28px;">No audited events match these filters yet. Events are recorded from this point forward.</td></tr>
            <?php else: foreach ($entries as $entry): ?>
              <tr>
                <td><?php echo e(date('M j, Y g:i:s A', strtotime($entry['created_at']))); ?></td>
                <td><?php echo e($entry['actor_label'] !== '' && $entry['actor_label'] !== null ? $entry['actor_label'] : 'Anonymous'); ?><span class="log-sub"><?php echo e($entry['actor_role'] ?: 'no session'); ?> &middot; <?php echo e($entry['ip_address'] ?: '—'); ?></span></td>
                <td><span class="log-type <?php echo audit_type_class($entry); ?>"><?php echo e($entry['action']); ?></span></td>
                <td><?php echo e($entry['target'] ?: '—'); ?><?php if (!empty($entry['details'])): ?><span class="log-sub"><?php echo e($entry['details']); ?></span><?php endif; ?></td>
                <td><?php echo e($entry['status']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php if (count($entries) === 300): ?><p style="color:#667085;font-size:12px;margin-top:10px;">Showing the 300 most recent matching events. Narrow the filters to see older ones.</p><?php endif; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
