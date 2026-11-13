<?php
require_once 'init.php';
require_login();

$user_id = (int)$_SESSION['user_id'];
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_profile']) || isset($_POST['upload_logo']))) {
  $is_logo_upload = isset($_POST['upload_logo']);
  $logo_path = null;

  if (!csrf_valid($_POST['csrf'] ?? '')) {
    $error = 'Your session expired. Please try again.';
  } elseif ($is_logo_upload && (!isset($_FILES['profile_logo']) || $_FILES['profile_logo']['error'] === UPLOAD_ERR_NO_FILE)) {
    $error = 'Choose an image before uploading.';
  }

  $fullname = trim($_POST['fullname'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  if (!$error && !$is_logo_upload) {
    if ($fullname === '') $error = 'Enter your full name.';
    elseif (mb_strlen($fullname) > 120 || mb_strlen($address) > 255) $error = 'Name or address is too long.';
    elseif ($phone !== '' && !preg_match('/^[0-9+()\s-]{7,20}$/', $phone)) $error = 'Enter a valid contact number.';
  }

  if (!$error && $is_logo_upload) {
    if ($_FILES['profile_logo']['error'] !== UPLOAD_ERR_OK || $_FILES['profile_logo']['size'] > 5 * 1024 * 1024) {
      $error = 'The image must be no larger than 5 MB.';
    } else {
      $image_info = @getimagesize($_FILES['profile_logo']['tmp_name']);
      $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
      $mime = $image_info['mime'] ?? '';
      if (!$image_info || !isset($allowed_types[$mime])) {
        $error = 'Upload a JPG, PNG, GIF, or WEBP image.';
      } else {
        $target_dir = __DIR__ . '/uploads/profile_logos';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $filename = 'user_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_types[$mime];
        if (move_uploaded_file($_FILES['profile_logo']['tmp_name'], $target_dir . '/' . $filename)) {
          $logo_path = 'uploads/profile_logos/' . $filename;
        } else {
          $error = 'Unable to save the uploaded image.';
        }
      }
    }
  }

  if (!$error && $is_logo_upload && $logo_path !== null) {
    $stmt = $conn->prepare('UPDATE users SET profile_logo = ? WHERE id = ?');
    $stmt->bind_param('si', $logo_path, $user_id);
    if ($stmt->execute()) { $msg = 'Your picture was updated.'; audit_log('PROFILE_UPDATED', 'own account', 'Profile picture changed'); }
    else $error = 'Unable to update your picture.';
    $stmt->close();
  } elseif (!$error && !$is_logo_upload) {
    // Only name, address and contact number are editable here. Email, role, barangay and
    // position are tied to the approved account and are changed by an administrator.
    $stmt = $conn->prepare('UPDATE users SET fullname = ?, address = ?, phone = ? WHERE id = ?');
    $stmt->bind_param('sssi', $fullname, $address, $phone, $user_id);
    if ($stmt->execute()) {
      $_SESSION['user_name'] = $fullname;
      $msg = 'Profile information updated.';
      audit_log('PROFILE_UPDATED', 'own account', 'Name, address or contact number changed');
    } else {
      $error = 'Unable to update your profile.';
    }
    $stmt->close();
  }
}

$stmt = $conn->prepare('SELECT fullname, address, phone, barangay, position, email, role, status, profile_logo, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$tier = normalize_role($user['role'] ?? '');
$role_label = $tier === 'head_admin' ? 'City Head Admin' : ($tier === 'admin' ? 'DRRMC Admin' : 'Barangay Staff');
$office = $tier === 'staff' ? barangay_label($user['barangay'] ?? '') : 'Caloocan City DRRMC';
$initial = strtoupper(substr((string)($user['fullname'] ?? '?'), 0, 1));
$status_label = ucfirst((string)($user['status'] ?? 'approved'));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>My Profile</title>
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
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-user" style="color:var(--primary);margin-right:8px;"></i>My Profile</h2>
      <p style="margin:4px 0 0;color:#666;font-size:13px;">Your account details and contact information.</p>
    </header>
    <main class="main-content" style="flex:1;overflow-y:auto;">
      <div class="settings-page">
        <?php if ($msg): ?><div class="notice"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="errors"><?= e($error) ?></div><?php endif; ?>

        <div class="settings-grid">
          <section class="settings-card">
            <div class="settings-card-head">
              <span class="settings-icon"><i class="fa-solid fa-image"></i></span>
              <div class="settings-head-text"><h3><?= $tier === 'staff' ? 'Barangay logo' : 'Profile picture' ?></h3><p>Shown in the sidebar and on your profile</p></div>
            </div>
            <div class="settings-card-body">
              <div class="settings-logo" style="margin-bottom:18px;">
                <?php if (!empty($user['profile_logo'])): ?>
                  <img class="settings-avatar" src="<?= e($user['profile_logo']) ?>" alt="">
                <?php else: ?>
                  <span class="settings-avatar placeholder"><?= e($initial) ?></span>
                <?php endif; ?>
                <div>
                  <strong style="display:block;font-size:15px;color:#1f2937;"><?= e($user['fullname'] ?? '') ?></strong>
                  <span style="font-size:12px;color:#667085;"><?= e($role_label) ?></span>
                </div>
              </div>
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="upload_logo" value="1">
                <div class="settings-field">
                  <label for="profile_logo">Choose an image</label>
                  <input id="profile_logo" name="profile_logo" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required>
                  <p class="settings-help">JPG, PNG, GIF or WEBP, up to 5 MB.</p>
                </div>
                <div class="settings-actions"><button type="submit" class="btn">Upload</button></div>
              </form>
            </div>
          </section>

          <section class="settings-card">
            <div class="settings-card-head">
              <span class="settings-icon"><i class="fa-solid fa-id-card"></i></span>
              <div class="settings-head-text"><h3>Account details</h3><p>Set when your account was approved</p></div>
              <span class="pill green"><i class="fa-solid fa-circle-check"></i> <?= e($status_label) ?></span>
            </div>
            <div class="settings-card-body">
              <dl class="settings-facts" style="margin:0;padding:0;border:0;">
                <div><dt>Email</dt><dd><?= e($user['email'] ?? '') ?></dd></div>
                <div><dt>Role</dt><dd><?= e($role_label) ?></dd></div>
                <div><dt><?= $tier === 'staff' ? 'Assigned barangay' : 'Office' ?></dt><dd><?= e($office) ?></dd></div>
                <div><dt>Position</dt><dd><?= !empty($user['position']) ? e($user['position']) : '&mdash;' ?></dd></div>
                <div><dt>Member since</dt><dd><?= !empty($user['created_at']) ? e(date('M j, Y', strtotime($user['created_at']))) : '&mdash;' ?></dd></div>
              </dl>
              <p class="settings-help" style="margin-top:16px;">Need to change your email, role or barangay? Ask a system administrator.</p>
            </div>
          </section>
        </div>

        <section class="settings-card">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-user-pen"></i></span>
            <div class="settings-head-text"><h3>Personal information</h3><p>Keep your contact details up to date</p></div>
          </div>
          <div class="settings-card-body">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="save_profile" value="1">
              <div class="settings-grid" style="margin-bottom:0;">
                <div class="settings-field"><label for="fullname">Full name</label><input id="fullname" name="fullname" value="<?= e($user['fullname'] ?? '') ?>" required maxlength="120"></div>
                <div class="settings-field"><label for="phone">Contact number</label><input id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="09XX XXX XXXX" maxlength="20"></div>
                <div class="settings-field settings-full"><label for="address">Complete address</label><input id="address" name="address" value="<?= e($user['address'] ?? '') ?>" maxlength="255"></div>
              </div>
              <div class="settings-actions"><button type="submit" class="btn">Save changes</button></div>
            </form>
          </div>
        </section>
      </div>
    </main>
  </div>
</div>
</body>
</html>
