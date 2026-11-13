<?php
require_once 'init.php';
require_once 'google_auth.php';
require_login();

$user_id = (int)$_SESSION['user_id'];
$google_mode = google_configured(); // sign-in is Google only once Google credentials are configured

// This device, as shown in the session list.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device';
$browser = stripos($ua, 'Edg') !== false ? 'Microsoft Edge' : (stripos($ua, 'Chrome') !== false ? 'Chrome' : (stripos($ua, 'Firefox') !== false ? 'Firefox' : 'Web Browser'));
$os = stripos($ua, 'Windows') !== false ? 'Windows' : (stripos($ua, 'Mac') !== false ? 'macOS' : (stripos($ua, 'Linux') !== false ? 'Linux' : 'Unknown OS'));
$device_type = stripos($ua, 'Mobile') !== false ? 'mobile' : (stripos($ua, 'Tablet') !== false ? 'tablet' : 'desktop');
$device = $browser . ' on ' . $os;
$session_key = hash('sha256', session_id());
$msg = '';
$error = '';

$stmt = $conn->prepare("INSERT INTO security_sessions (user_id, session_key, device, device_type, ip_address, location) VALUES (?, ?, ?, ?, ?, 'Caloocan, PH') ON DUPLICATE KEY UPDATE device = VALUES(device), last_active = CURRENT_TIMESTAMP, revoked = 0");
$stmt->bind_param('issss', $user_id, $session_key, $device, $device_type, $ip);
$stmt->execute();
$stmt->close();
$stmt = $conn->prepare('INSERT IGNORE INTO security_preferences (user_id) VALUES (?)');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_valid($_POST['csrf'] ?? '')) {
    $error = 'Your session expired. Please try again.';
  } elseif (isset($_POST['change_password'])) {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account || !password_verify($current, $account['password'])) {
      $error = 'Your current password is incorrect.';
      audit_log('PASSWORD_CHANGE_FAILED', 'own account', 'Incorrect current password', 'Failed');
    } elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)) {
      $error = 'Use at least 8 characters, one uppercase letter, one number, and one symbol.';
    } elseif ($new !== $confirm) {
      $error = 'The new passwords do not match.';
    } else {
      $hash = password_hash($new, PASSWORD_DEFAULT);
      $update = $conn->prepare('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?');
      $update->bind_param('si', $hash, $user_id);
      if ($update->execute()) { $msg = 'Password updated successfully.'; audit_log('PASSWORD_CHANGED', 'own account'); }
      else $error = 'Unable to update your password.';
      $update->close();
    }
  } elseif (isset($_POST['update_two_factor'])) {
    $enabled = !empty($_POST['two_factor_enabled']) ? 1 : 0;
    $method = $_POST['two_factor_method'] ?? 'authenticator';
    if (!in_array($method, ['sms', 'authenticator', 'email'], true)) $method = 'authenticator';
    $stmt = $conn->prepare('UPDATE users SET two_factor_enabled = ?, two_factor_method = ?, two_factor_verified_at = IF(?, NOW(), two_factor_verified_at) WHERE id = ?');
    $stmt->bind_param('isii', $enabled, $method, $enabled, $user_id);
    $stmt->execute();
    $stmt->close();
    $msg = 'Two-factor authentication preference saved.';
    audit_log('TWO_FACTOR_UPDATED', 'own account', $enabled ? 'Enabled via ' . $method : 'Disabled');
  } elseif (isset($_POST['update_notifications'])) {
    $values = [!empty($_POST['email_new_device']) ? 1 : 0, !empty($_POST['email_password_change']) ? 1 : 0, !empty($_POST['email_failed_login']) ? 1 : 0];
    $stmt = $conn->prepare('UPDATE security_preferences SET email_new_device = ?, email_password_change = ?, email_failed_login = ? WHERE user_id = ?');
    $stmt->bind_param('iiii', $values[0], $values[1], $values[2], $user_id);
    $stmt->execute();
    $stmt->close();
    $msg = 'Notification preferences saved.';
  } elseif (isset($_POST['logout_session'])) {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $stmt = $conn->prepare('UPDATE security_sessions SET revoked = 1 WHERE id = ? AND user_id = ? AND session_key <> ?');
    $stmt->bind_param('iis', $session_id, $user_id, $session_key);
    $stmt->execute();
    $stmt->close();
    $msg = 'The selected session was signed out.';
  } elseif (isset($_POST['logout_other_sessions'])) {
    $stmt = $conn->prepare('UPDATE security_sessions SET revoked = 1 WHERE user_id = ? AND session_key <> ?');
    $stmt->bind_param('is', $user_id, $session_key);
    $stmt->execute();
    $stmt->close();
    $msg = 'All other sessions were signed out.';
    audit_log('SESSIONS_REVOKED', 'own account', 'All other sessions signed out');
  } elseif (isset($_POST['trust_device'])) {
    $stmt = $conn->prepare('INSERT IGNORE INTO trusted_devices (user_id, device, device_key) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $user_id, $device, $session_key);
    $stmt->execute();
    $stmt->close();
    $msg = 'This device is now trusted.';
  } elseif (isset($_POST['remove_trusted'])) {
    $trusted_id = (int)($_POST['trusted_id'] ?? 0);
    $stmt = $conn->prepare('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $trusted_id, $user_id);
    $stmt->execute();
    $stmt->close();
    $msg = 'Trusted device removed.';
  }
}

// ----- data for the page -----
$stmt = $conn->prepare('SELECT fullname, email, role, status, barangay, profile_logo, created_at, two_factor_enabled, two_factor_method, password_changed_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$prefs = ['email_new_device' => 1, 'email_password_change' => 1, 'email_failed_login' => 1];
$stmt = $conn->prepare('SELECT email_new_device, email_password_change, email_failed_login FROM security_preferences WHERE user_id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$prefs = $stmt->get_result()->fetch_assoc() ?: $prefs;
$stmt->close();

$sessions = [];
$stmt = $conn->prepare('SELECT id, device, device_type, ip_address, location, last_active, session_key FROM security_sessions WHERE user_id = ? AND revoked = 0 ORDER BY last_active DESC');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $sessions[] = $row;
$stmt->close();

$trusted = [];
$stmt = $conn->prepare('SELECT id, device, last_used FROM trusted_devices WHERE user_id = ? ORDER BY last_used DESC');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $trusted[] = $row;
$stmt->close();

$activity = [];
$stmt = $conn->prepare('SELECT created_at, ip_address, location, device, status FROM security_login_activity WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $activity[] = $row;
$stmt->close();

$tier = normalize_role($user['role'] ?? '');
$role_label = $tier === 'head_admin' ? 'City Head Admin' : ($tier === 'admin' ? 'DRRMC Admin' : 'Barangay Staff');
$office = $tier === 'staff' ? barangay_label($user['barangay'] ?? '') : 'Caloocan City DRRMC';
$two_factor_on = !empty($user['two_factor_enabled']);
$two_factor_method = $user['two_factor_method'] ?: 'authenticator';
$other_sessions = count(array_filter($sessions, function ($s) use ($session_key) { return $s['session_key'] !== $session_key; }));
$session_limit = 5;
$hidden_sessions = max(0, count($sessions) - $session_limit);
$shown_sessions = array_slice($sessions, 0, $session_limit);
$device_icons = ['mobile' => 'fa-mobile-screen-button', 'tablet' => 'fa-tablet-screen-button', 'desktop' => 'fa-desktop'];
$initial = strtoupper(substr((string)($user['fullname'] ?? '?'), 0, 1));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>System Settings</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-shield-halved" style="color:var(--primary);margin-right:8px;"></i>System Settings</h2>
      <p style="margin:4px 0 0;color:#666;font-size:13px;">Manage how you sign in, where you are signed in, and your account protection.</p>
    </header>
    <main class="main-content" style="flex:1;overflow-y:auto;">
      <div class="settings-page">
        <?php if ($msg): ?><div class="notice"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="errors"><?= e($error) ?></div><?php endif; ?>

        <section class="settings-card" style="margin-bottom:20px;">
          <div class="settings-card-body">
            <div class="settings-account">
              <?php if (!empty($user['profile_logo'])): ?>
                <img class="settings-avatar" src="<?= e($user['profile_logo']) ?>" alt="">
              <?php else: ?>
                <span class="settings-avatar placeholder"><?= e($initial) ?></span>
              <?php endif; ?>
              <div class="settings-account-main">
                <h2><?= e($user['fullname'] ?? '') ?></h2>
                <p><?= e($user['email'] ?? '') ?></p>
              </div>
              <span class="pill green"><i class="fa-solid fa-circle-check"></i> Account active</span>
            </div>
            <dl class="settings-facts">
              <div><dt>Role</dt><dd><?= e($role_label) ?></dd></div>
              <div><dt><?= $tier === 'staff' ? 'Assigned barangay' : 'Office' ?></dt><dd><?= e($office) ?></dd></div>
              <div><dt>Sign-in method</dt><dd><?= $google_mode ? 'Google account' : 'Email and password' ?></dd></div>
              <div><dt>Member since</dt><dd><?= !empty($user['created_at']) ? e(date('M j, Y', strtotime($user['created_at']))) : '&mdash;' ?></dd></div>
            </dl>
          </div>
        </section>

        <div class="settings-grid">
          <section class="settings-card">
            <div class="settings-card-head">
              <span class="settings-icon"><i class="fa-solid fa-key"></i></span>
              <div class="settings-head-text"><h3><?= $google_mode ? 'Sign-in & password' : 'Change password' ?></h3><p><?= $google_mode ? 'Managed by your Google account' : 'Last changed: ' . (!empty($user['password_changed_at']) ? e(date('M j, Y g:i A', strtotime($user['password_changed_at']))) : 'not available') ?></p></div>
            </div>
            <div class="settings-card-body">
              <?php if ($google_mode): ?>
                <p class="settings-note">You sign in with your Google account, so your password and two-step verification are managed by Google, not by this system.</p>
                <a class="btn-ghost" href="https://myaccount.google.com/security" target="_blank" rel="noopener"><i class="fa-brands fa-google"></i> Manage Google security</a>
              <?php else: ?>
                <form method="post" id="passwordForm" autocomplete="off">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="change_password" value="1">
                  <div class="settings-field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
                  <div class="settings-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" required autocomplete="new-password"></div>
                  <ul class="rules" id="passwordRules">
                    <li data-rule="length">8+ characters</li><li data-rule="upper">One uppercase letter</li>
                    <li data-rule="number">One number</li><li data-rule="symbol">One symbol</li>
                  </ul>
                  <div class="settings-field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password"><p class="settings-help" id="matchHelp"></p></div>
                  <div class="settings-actions"><button type="submit" class="btn" id="passwordSubmit" disabled>Update password</button></div>
                </form>
              <?php endif; ?>
            </div>
          </section>

          <section class="settings-card">
            <div class="settings-card-head">
              <span class="settings-icon"><i class="fa-solid fa-desktop"></i></span>
              <div class="settings-head-text"><h3>Active sessions</h3><p>Devices currently signed in to your account</p></div>
            </div>
            <div class="settings-card-body">
              <ul class="settings-list">
                <?php foreach ($shown_sessions as $session): $is_current = $session['session_key'] === $session_key; ?>
                  <li>
                    <span class="item-icon"><i class="fa-solid <?= e($device_icons[$session['device_type']] ?? 'fa-desktop') ?>"></i></span>
                    <div class="item-main">
                      <strong><?= e($session['device']) ?> <?php if ($is_current): ?><span class="pill green" style="margin-left:4px;">This device</span><?php endif; ?></strong>
                      <span><?= e($session['ip_address']) ?> &middot; <?= e($session['location']) ?> &middot; <?= e(date('M j, g:i A', strtotime($session['last_active']))) ?></span>
                    </div>
                    <?php if (!$is_current): ?>
                      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="logout_session" value="1"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>"><button class="btn-ghost btn-danger-ghost btn-sm" type="submit">Sign out</button></form>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
                <?php if (!$sessions): ?><li><p class="settings-empty">No active sessions recorded.</p></li><?php endif; ?>
              </ul>
              <?php if ($hidden_sessions > 0): ?><p class="settings-help" style="margin-top:12px;">and <?= (int)$hidden_sessions ?> more older session<?= $hidden_sessions === 1 ? '' : 's' ?>.</p><?php endif; ?>
              <?php if ($other_sessions > 0): ?>
                <form method="post" style="margin-top:16px;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="logout_other_sessions" value="1"><button class="btn-ghost btn-danger-ghost" type="submit"><i class="fa-solid fa-right-from-bracket"></i> Sign out all other devices</button></form>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <section class="settings-card" style="margin-bottom:20px;">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-list-check"></i></span>
            <div class="settings-head-text"><h3>Recent sign-in activity</h3><p>Your latest sign-in attempts and security events</p></div>
          </div>
          <div class="settings-table-wrap">
            <table class="settings-table">
              <thead><tr><th>Date &amp; time</th><th>IP address</th><th>Location</th><th>Device</th><th>Result</th></tr></thead>
              <tbody>
                <?php foreach ($activity as $entry): ?>
                  <tr>
                    <td><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td>
                    <td><?= e($entry['ip_address']) ?></td>
                    <td><?= e($entry['location']) ?></td>
                    <td><?= e($entry['device']) ?></td>
                    <td><span class="pill <?= $entry['status'] === 'Failed' ? 'red' : 'green' ?>"><?= e($entry['status']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$activity): ?><tr><td colspan="5" style="color:#98a2b3;">No sign-in activity recorded yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

      </div>
    </main>
  </div>
</div>
<?php if (!$google_mode): ?>
<script>
(function () {
  var current = document.getElementById('current_password'), next = document.getElementById('new_password'), confirm = document.getElementById('confirm_password');
  var submit = document.getElementById('passwordSubmit'), help = document.getElementById('matchHelp');
  var rules = { length: function (v) { return v.length >= 8; }, upper: function (v) { return /[A-Z]/.test(v); }, number: function (v) { return /[0-9]/.test(v); }, symbol: function (v) { return /[^A-Za-z0-9]/.test(v); } };
  function check() {
    var value = next.value, all = true;
    Object.keys(rules).forEach(function (key) {
      var ok = rules[key](value);
      document.querySelector('[data-rule="' + key + '"]').classList.toggle('ok', ok);
      if (!ok) all = false;
    });
    var matches = confirm.value !== '' && confirm.value === value;
    help.textContent = confirm.value !== '' && !matches ? 'Passwords do not match.' : '';
    help.style.color = '#b42318';
    submit.disabled = !(all && matches && current.value !== '');
  }
  [current, next, confirm].forEach(function (input) { input.addEventListener('input', check); });
})();
</script>
<?php endif; ?>
</body>
</html>
