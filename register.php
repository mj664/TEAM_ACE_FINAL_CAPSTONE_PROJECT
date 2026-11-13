<?php
require_once 'init.php';
require_once 'ai_ocr.php';
require_once 'ai_face.php';

// Registration is only reachable after a verified Google sign-in (see google_callback.php).
$identity = $_SESSION['google_identity'] ?? null;
if (!$identity || empty($identity['email'])) {
    header('Location: login.php?err=need_google');
    exit;
}
$google_email = $identity['email'];

// Already registered? Send them where they belong instead of showing the form.
$exists_stmt = $conn->prepare('SELECT status FROM users WHERE email = ? LIMIT 1');
$exists_stmt->bind_param('s', $google_email);
$exists_stmt->execute();
$existing = $exists_stmt->get_result()->fetch_assoc();
$exists_stmt->close();
if ($existing) {
    header('Location: ' . ($existing['status'] === 'pending' ? 'pending_approval.php' : 'login.php'));
    exit;
}

$ocr_enabled = ai_ocr_configured();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if ($fullname === '') $errors[] = 'Enter your full name.';
    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) $errors[] = 'Enter a valid mobile or phone number.';

    $id_ext = '';
    $id_mime = '';
    $file = $_FILES['verification'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Upload a clear image of your Government/Staff ID.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 8 * 1024 * 1024) {
        $errors[] = 'The ID image must be no larger than 8 MB.';
    } else {
        $image_info = @getimagesize($file['tmp_name']);
        $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$image_info || !isset($allowed_types[$image_info['mime']])) {
            $errors[] = 'The ID must be a JPG, PNG or WEBP image.';
        } else {
            $id_mime = $image_info['mime'];
            $id_ext = $allowed_types[$id_mime];
        }
    }

    // Staff type, barangay and address come from the ID, never from the browser.
    $detected = ['staff_type' => '', 'barangay' => null, 'address' => null];
    $ai_used = false;
    $face_result = null;
    if (!$errors) {
        $scan = null;
        $stored = $_SESSION['ocr_result'] ?? null;
        if ($stored && hash_equals($stored['hash'], hash_file('sha256', $file['tmp_name']))) {
            $scan = $stored; // already scanned when the file was chosen: no second API call
        } elseif ($ocr_enabled && (int)($_SESSION['ocr_scans'] ?? 0) < (int)ai_config()['max_scans_per_session']) {
            $_SESSION['ocr_scans'] = (int)($_SESSION['ocr_scans'] ?? 0) + 1;
            set_time_limit(90);
            $result = ai_ocr_extract($file['tmp_name'], $id_mime);
            if (!empty($result['ok'])) {
                $scan = $result;
            } elseif (!empty($result['not_id'])) {
                $errors[] = $result['message'];
            } else {
                audit_log('ID_SCAN_FAILED', $google_email, $result['error_class'] ?? 'Unreadable ID', 'Failed', null, $google_email);
            }
        }
        if ($scan) {
            $face_result = $scan['face'] ?? null;
            $ai_used = true;
            $detected = array_intersect_key($scan['fields'], $detected) + $detected;
        }
    }

    // One account per barangay: a barangay that already has a staff account (or a pending one) cannot get another.
    if (!$errors && ($detected['staff_type'] ?? '') !== 'drrmc' && $detected['barangay'] !== null && ($holder = barangay_account_taken($detected['barangay']))) {
        $errors[] = barangay_taken_message($detected['barangay'], $holder) . ' If you are replacing the current staff member, ask the Head Admin to deactivate the old account first.';
        audit_log('REGISTRATION_BLOCKED', $google_email, 'Barangay ' . $detected['barangay'] . ' already has an account (#' . $holder['id'] . ')', 'Failed', null, $google_email);
    }

    // Face on the ID must match the Google account photo (checked when the ID was scanned; checked here if it was not).
    if (!$errors && $ocr_enabled) {
        if (empty($face_result)) $face_result = ai_face_check($file['tmp_name'], $id_mime, $identity['picture'] ?? '');
        if (!empty($face_result['blocked'])) {
            $errors[] = $face_result['message'];
            audit_log('REGISTRATION_BLOCKED', $google_email, 'Face check: ' . $face_result['verdict'], 'Failed', null, $google_email);
        }
    }

    if (!$errors) {
        $target_dir = __DIR__ . '/uploads/ids';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $filename = 'id_' . bin2hex(random_bytes(12)) . '.' . $id_ext;
        if (!move_uploaded_file($file['tmp_name'], $target_dir . '/' . $filename)) {
            $errors[] = 'Unable to save the uploaded ID. Please try again.';
        } else {
            $upload_path = 'uploads/ids/' . $filename;
            $type = $detected['staff_type'];
            $role = $type === 'drrmc' ? 'official' : 'user'; // requested tier; the Head Admin confirms on approval
            $position = $type === 'drrmc' ? 'DRRMC Staff' : ($type === 'barangay' ? 'Barangay Staff' : 'Staff (type not detected)');
            $barangay_value = $type === 'drrmc' ? 'Caloocan' : ($detected['barangay'] !== null ? (string)$detected['barangay'] : '');
            $address = (string)($detected['address'] ?? '');
            $unusable_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); // sign-in is Google only
            try {
                $stmt = $conn->prepare("INSERT INTO users (fullname, address, phone, barangay, position, verification_file, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->bind_param('sssssssss', $fullname, $address, $phone, $barangay_value, $position, $upload_path, $google_email, $unusable_password, $role);
                $stmt->execute();
                $stmt->close();
                unset($_SESSION['ocr_result']);
                audit_log('ACCOUNT_REGISTERED', $google_email, $position . '; barangay ' . ($barangay_value !== '' ? $barangay_value : 'not detected') . '; ' . ($ai_used ? 'read from ID by AI' : 'ID not machine-read') . '; face check: ' . ($face_result['verdict'] ?? 'not run'), 'Success', null, $google_email);
                // Let the applicant know by email that the registration was received and is waiting for approval.
                mail_account_status('registered', ['fullname' => $fullname, 'email' => $google_email, 'role' => $role, 'position' => $position, 'barangay' => $barangay_value]);
                header('Location: pending_approval.php');
                exit;
            } catch (mysqli_sql_exception $ex) {
                @unlink($target_dir . '/' . $filename);
                if ($ex->getCode() === 1062) { header('Location: pending_approval.php'); exit; }
                $errors[] = 'Unable to submit your registration. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Register - DRRMC Caloocan Hub</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?php echo e(asset_v('assets/style.css')); ?>">
  <style>.drrmc-loader:not(.active) { display:none; }</style>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .reg-shell { display:flex; min-height:100vh; align-items:center; justify-content:center; padding:24px; }
    .reg-card { width:100%; max-width:640px; background:#fff; border-radius:18px; box-shadow:0 10px 30px rgba(0,0,0,.12); padding:32px 34px; }
    .reg-head { display:flex; align-items:center; gap:14px; margin-bottom:6px; }
    .reg-head img { width:60px; height:60px; border-radius:50%; object-fit:cover; }
    .reg-head h2 { margin:0; color:var(--primary); font-size:22px; }
    .reg-head p { margin:2px 0 0; color:#667085; font-size:12px; }
    .reg-google { display:flex; align-items:center; gap:10px; margin:18px 0; padding:10px 12px; background:#eef8f2; border:1px solid #cfe6d8; border-radius:10px; font-size:13px; color:#1e4d37; }
    .reg-google img { width:34px; height:34px; border-radius:50%; object-fit:cover; }
    .reg-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .reg-grid .wide { grid-column:1 / -1; }
    .reg-grid label { display:block; margin-bottom:6px; font-weight:700; font-size:13px; color:#333; }
    .reg-grid input { width:100%; padding:11px 12px; border:1px solid #d9dfe7; border-radius:8px; font:inherit; font-size:14px; }
    .reg-grid input[readonly] { background:#f4f6f8; color:#667085; }
    .reg-auto { margin:0; padding:11px 14px; background:#f8fafb; border:1px dashed #cbd5e1; border-radius:10px; color:#475467; font-size:12px; line-height:1.6; }
    @media (max-width:640px) { .reg-grid { grid-template-columns:1fr; } .reg-card { padding:24px 20px; } }
  </style>
</head>
<body class="auth-page">
<?php auth_video_background(); ?>
<div class="reg-shell">
  <div class="reg-card">
    <div class="reg-head">
      <img src="assets/cdrrmd-logo.jpg" alt="DRRMC Caloocan logo">
      <div><h2>Complete your registration</h2><p>Your Google account isn't registered yet. Submit your details for Head Admin approval.</p></div>
    </div>

    <div class="reg-google">
      <?php if (!empty($identity['picture'])): ?><img src="<?php echo e($identity['picture']); ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
      <div>Signed in with Google as <strong><?php echo e($google_email); ?></strong></div>
    </div>

    <?php if ($errors): ?><div class="errors" style="margin-bottom:16px;"><?php foreach ($errors as $err): ?><div>&bull; <?php echo e($err); ?></div><?php endforeach; ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="registerForm">
      <div class="reg-grid">
        <div class="wide"><label>Google email (verified)</label><input type="email" value="<?php echo e($google_email); ?>" readonly></div>
        <div class="wide">
          <label for="verification">Step 1: Government / Staff ID (image)</label>
          <div class="verification-section">
            <p>Upload a clear photo or scan of your ID (JPG, PNG or WEBP, up to 8 MB).</p>
            <input id="verification" type="file" name="verification" accept="image/jpeg,image/png,image/webp" required style="margin-top:10px;">
          </div>
          <div class="notice" id="scanResult" style="display:none;margin-top:12px;"></div>
        </div>
        <div class="wide"><strong style="font-size:13px;color:#333;">Step 2: Confirm your details</strong></div>
        <div class="wide"><label for="fullname">Full name</label><input id="fullname" name="fullname" required value="<?php echo e($_POST['fullname'] ?? ($identity['name'] ?? '')); ?>"></div>
        <div class="wide"><label for="phone">Contact number</label><input id="phone" name="phone" required placeholder="09XX XXX XXXX" value="<?php echo e($_POST['phone'] ?? ''); ?>"></div>
        <p class="reg-auto wide"><?php echo $ocr_enabled
            ? 'Your staff type, assigned barangay and address are read automatically from your ID, so you don\'t need to enter them.'
            : 'Your staff type, assigned barangay and address are taken from your ID by the reviewing Head Admin, so you don\'t need to enter them.'; ?></p>
      </div>
      <button type="submit" class="btn" style="width:100%;margin-top:18px;padding:13px;font-size:15px;">Submit for approval</button>
      <p style="text-align:center;margin-top:14px;font-size:12px;"><a href="logout.php">Cancel and sign out</a></p>
    </form>
  </div>
</div>

<div class="drrmc-loader" id="drrmcLoader" role="status" aria-live="polite">
  <div class="drrmc-loader-logo"><img src="assets/cdrrmd-logo.jpg" alt=""></div>
  <div class="drrmc-loader-text" id="drrmcLoaderText">Submitting your registration&hellip;</div>
</div>
<script>
  (function () {
    var form = document.getElementById('registerForm'), loader = document.getElementById('drrmcLoader'), loaderText = document.getElementById('drrmcLoaderText');
    form.addEventListener('submit', function () { if (form.checkValidity()) loader.classList.add('active'); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) loader.classList.remove('active'); });

    // ID scan: runs when a file is chosen. It only shows what was detected and pre-fills the phone number;
    // the server keeps the trusted result and ignores anything sent from the browser.
    var OCR_ENABLED = <?php echo $ocr_enabled ? 'true' : 'false'; ?>;
    var CSRF = '<?php echo e(csrf_token()); ?>';
    var fileInput = document.getElementById('verification'), result = document.getElementById('scanResult'), phone = document.getElementById('phone'), submitBtn = form.querySelector('button[type="submit"]');
    function showResult(kind, text) { result.style.display = 'block'; result.className = kind === 'ok' ? 'notice' : 'errors'; result.textContent = text; }
    if (OCR_ENABLED) fileInput.addEventListener('change', function () {
      result.style.display = 'none';
      var file = fileInput.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) { showResult('err', 'This image is too large to scan automatically (5 MB max). You can still submit it and the Head Admin will review it.'); return; }
      var body = new FormData();
      body.append('csrf', CSRF);
      body.append('id_image', file);
      loaderText.textContent = 'Reading your ID and checking your photo…';
      loader.classList.add('active');
      fetch('ocr_id.php', { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          submitBtn.disabled = !!d.blocked; submitBtn.style.opacity = d.blocked ? '.5' : ''; submitBtn.style.cursor = d.blocked ? 'not-allowed' : '';
          if (!d.ok) { showResult('err', (d.blocked ? '⛔ ' : '') + (d.message || 'We could not read this ID automatically. You can still submit it and the Head Admin will review it.')); return; }
          var f = d.fields, found = [];
          if (f.staff_type) found.push(f.staff_type === 'drrmc' ? 'DRRMC Staff' : 'Barangay Staff');
          if (f.barangay) found.push('Barangay ' + f.barangay);
          if (f.address) found.push(f.address);
          if (f.phone && !phone.value) { phone.value = f.phone; phone.style.background = '#fffbe6'; found.push('contact number'); }
          showResult('ok', (d.face && d.face.verdict === 'match' ? '✅ ' + d.face.message + ' ' : '') + (found.length ? 'Read from your ID: ' + found.join(' · ') + '. ' : 'We could not read details from this ID; the Head Admin will review it. ') + 'Please check your contact number below.' + (d.note ? ' Note: ' + d.note : '') + (d.face && d.face.verdict !== 'match' && d.face.message ? ' ' + d.face.message : ''));
        })
        .catch(function () { showResult('err', 'Scanning failed. You can still submit your ID and the Head Admin will review it.'); })
        .then(function () { loader.classList.remove('active'); loaderText.textContent = 'Submitting your registration…'; });
    });
  })();
</script>
</body>
</html>
