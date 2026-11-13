<?php
require_once 'init.php';

$identity = $_SESSION['google_identity'] ?? null;
if (!$identity || empty($identity['email'])) {
    header('Location: login.php?err=need_google');
    exit;
}

require_once 'google_auth.php';

// Anything other than "still pending" (approved, rejected, not registered, several accounts) is routed elsewhere.
$target = google_resolve_login($identity, false);
if ($target !== 'pending_approval.php') { header('Location: ' . $target); exit; }
$user = google_route_for_email($identity['email'])['users'][0];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Account Pending Approval - DRRMC Caloocan Hub</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?php echo e(asset_v('assets/style.css')); ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .pending-shell { display:flex; min-height:100vh; align-items:center; justify-content:center; padding:24px; }
    .pending-card { width:100%; max-width:460px; background:#fff; border-radius:18px; box-shadow:0 10px 30px rgba(0,0,0,.12); padding:38px 34px; text-align:center; }
    .pending-card .drrmc-loader-logo { margin:12px auto 28px; }
    .pending-card h1 { margin:0 0 8px; font-size:22px; color:var(--primary-dark); }
    .pending-badge { display:inline-block; padding:5px 12px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:12px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
    .pending-card p { color:#667085; font-size:13px; line-height:1.6; }
    .pending-meta { margin:18px 0 6px; padding:14px 16px; background:#f8fafb; border:1px solid #e5e7eb; border-radius:10px; text-align:left; font-size:13px; color:#344054; }
    .pending-meta div { display:flex; justify-content:space-between; gap:12px; padding:4px 0; }
    .pending-meta span:first-child { color:#667085; }
  </style>
</head>
<body class="auth-page">
<?php auth_video_background(); ?>
<div class="pending-shell">
  <div class="pending-card">
    <div class="drrmc-loader-logo"><img src="assets/cdrrmd-logo.jpg" alt="DRRMC logo"></div>
    <span class="pending-badge">Pending approval</span>
    <h1 style="margin-top:12px;">Account Pending Approval</h1>
    <p>Your registration was received. The City Head Admin has been notified and will review your ID. This page updates automatically and signs you in as soon as you are approved.</p>
    <div class="pending-meta">
      <div><span>Name</span><strong><?php echo e($user['fullname']); ?></strong></div>
      <div><span>Google email</span><strong><?php echo e($user['email']); ?></strong></div>
      <div><span>Type</span><strong><?php echo e($user['position']); ?></strong></div>
      <div><span>Submitted</span><strong><?php echo e(date('M j, Y g:i A', strtotime($user['created_at']))); ?></strong></div>
    </div>
    <p style="margin-top:14px;font-size:12px;"><a href="logout.php">Sign out</a></p>
  </div>
</div>
<script>
  (function () {
    function check() {
      fetch('pending_status.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.redirect) window.location.href = d.redirect; })
        .catch(function () {});
    }
    setInterval(check, 5000);
  })();
</script>
</body>
</html>
