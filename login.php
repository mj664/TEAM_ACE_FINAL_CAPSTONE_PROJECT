<?php
require_once 'init.php';
require_once 'google_auth.php';

// Already signed in: go straight to the right dashboard.
if (!empty($_SESSION['user_id']) && current_user_role() !== '') {
    header('Location: ' . dashboard_for_role($_SESSION['user_role']));
    exit;
}

$google_ready = google_configured();
$error_messages = [
    'not_configured'   => 'Google sign-in is not configured yet.',
    'google_cancelled' => 'Google sign-in was cancelled.',
    'google_failed'    => 'Google sign-in could not be completed. Please try again.',
    'unverified'       => 'Your Google email address is not verified.',
    'state'            => 'Your sign-in session expired. Please try again.',
    'rejected'         => 'This account was not approved. Please contact the DRRM office.',
    'need_google'      => 'Please sign in with Google first to register.',
];
$error = $error_messages[$_GET['err'] ?? ''] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($google_ready) {
        $error = 'Password sign-in is disabled. Please sign in with Google.';
    } else {
        // Setup mode only: temporary email/password sign-in until Google credentials are configured.
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $conn->prepare('SELECT id, email, password, role, status, fullname, barangay FROM users WHERE email = ? ORDER BY id ASC');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $accounts = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        // One email can hold several accounts (one per role): use the one whose password matches.
        $u = $accounts[0] ?? null;
        foreach ($accounts as $candidate) {
            if (password_verify($password, $candidate['password'])) { $u = $candidate; break; }
        }
        if ($u) {
            if (password_verify($password, $u['password'])) {
                if ($u['status'] !== 'approved') {
                    audit_log('LOGIN_BLOCKED', $email, 'Account status: ' . $u['status'], 'Failed', (int)$u['id'], $email);
                    $error = 'Account not approved yet.';
                } else {
                    header('Location: ' . login_user_session($u, 'password (setup mode)'));
                    exit;
                }
            } else {
                $failed_stmt = $conn->prepare("INSERT INTO security_login_activity (user_id, ip_address, location, device, status) VALUES (?, ?, 'Caloocan, PH', 'Login form', 'Failed')");
                if ($failed_stmt) { $failed_ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; $failed_uid = (int)$u['id']; $failed_stmt->bind_param('is', $failed_uid, $failed_ip); $failed_stmt->execute(); $failed_stmt->close(); }
                audit_log('LOGIN_FAILED', $email, 'Incorrect password', 'Failed', (int)$u['id'], $email);
                $error = 'Invalid credentials.';
            }
        } else {
            audit_log('LOGIN_FAILED', $email, 'Unknown account', 'Failed', null, $email);
            $error = 'Invalid credentials.';
        }
    }
}
$redirect_uri = google_config()['redirect_uri'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sign in - DRRMC Caloocan Hub</title>
  <?php app_favicon(); ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };</script>
  <style>
    html, body, button, input { font-family: 'Inter', sans-serif; }
    /* Loader styles kept inline so the page works even if assets/style.css is missing. */
    .drrmc-loader { position:fixed; inset:0; z-index:50; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:22px; background:rgba(15,23,42,.6); -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px); }
    .drrmc-loader:not(.active) { display:none; }
    .drrmc-loader-text { font-size:15px; font-weight:600; color:#fff; text-shadow:0 1px 3px rgba(0,0,0,.4); }
    @keyframes drrmc-spin { to { transform:rotate(360deg); } }

    /* Spinning round logo (card: slow spin; loader: fast spin with a glowing ring) */
    .spin-logo { position:relative; border-radius:50%; }
    .spin-logo { perspective:600px; }
    .spin-logo img { display:block; width:100%; height:100%; border-radius:50%; object-fit:cover; animation:drrmc-coin 3.2s ease-in-out infinite; filter:drop-shadow(0 6px 10px rgba(0,0,0,.35)); }
    /* Coin flip: turns sideways (gets thin) and comes back to face you. */
    @keyframes drrmc-coin { 0% { transform:rotateY(0deg); } 100% { transform:rotateY(360deg); } }
    .spin-logo::after { content:''; position:absolute; inset:-6px; border-radius:50%; border:2px solid rgba(52,211,153,.55); border-top-color:transparent; border-bottom-color:transparent; animation:drrmc-spin 3s linear infinite reverse; }
    .spin-logo:hover img { animation-duration:1.4s; }
    .spin-logo.loading img { animation:drrmc-spin 1.2s linear infinite; }
    .spin-logo.loading::after { inset:-10px; border-width:3px; border-color:#34d399; border-bottom-color:transparent; border-left-color:transparent; animation-duration:.9s; animation-direction:normal; box-shadow:0 0 24px rgba(52,211,153,.35); }
    @media (prefers-reduced-motion: reduce) { .spin-logo img, .spin-logo::after { animation:none; } }
  </style>
</head>
<body class="bg-slate-900 font-sans antialiased text-slate-900">
<?php auth_video_background(); ?>
<div class="relative min-h-screen flex items-center justify-center p-4">
  <div class="max-w-md w-full bg-white/10 border border-white/20 backdrop-blur-xl p-8 rounded-2xl shadow-2xl text-center">
    <div class="spin-logo w-24 h-24 mx-auto mb-4">
      <img src="assets/cdrrmd-logo.jpg" alt="DRRMC Caloocan logo"
           onerror="this.parentNode.style.display='none'; document.getElementById('logoFallback').style.display='flex';">
    </div>
    <div id="logoFallback" class="w-20 h-20 mx-auto mb-3 rounded-full bg-emerald-600 text-white items-center justify-center text-xl font-bold" style="display:none;" aria-hidden="true">DRRM</div>

    <h1 class="text-2xl font-bold text-white drop-shadow">DRRMC Caloocan Hub</h1>
    <p class="text-sm text-slate-200 font-medium mt-1">An AI-Enhanced DRRMC Inventory Management System using Machine Learning for Barangays in South Caloocan City</p>
    <p class="text-xs text-emerald-300 font-semibold uppercase tracking-wider mt-0.5">South Caloocan Division</p>

    <div class="mt-8">
      <?php if ($error) echo '<div class="mb-4 rounded-xl border border-red-300/50 bg-red-500/20 px-4 py-3 text-left text-sm text-red-100">' . e($error) . '</div>'; ?>

      <a id="googleSignIn" href="google_login.php"
         class="w-full border border-slate-300 hover:border-slate-400 bg-white hover:bg-slate-50 transition-all rounded-xl py-3 px-4 flex items-center justify-center gap-3 shadow-sm font-semibold text-slate-700 no-underline<?php echo $google_ready ? '' : ' opacity-50 pointer-events-none'; ?>"<?php echo $google_ready ? '' : ' aria-disabled="true"'; ?>>
        <svg class="w-5 h-5 shrink-0" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        <span>Sign in with Google</span>
      </a>
    </div>

    <p class="text-xs text-slate-300 text-center mt-6 leading-relaxed">Only registered, approved accounts can sign in. New staff: sign in with Google and you will be taken to registration.</p>

    <?php if (!$google_ready): ?>
      <div class="mt-6 rounded-xl border border-dashed border-amber-400 bg-amber-50 p-4 text-left text-xs text-amber-800 leading-relaxed">
        <strong class="font-semibold">Setup mode: Google sign-in is not configured.</strong><br>
        Copy <b>google_config.sample.php</b> to <b>google_config.php</b>, add your Google credentials, and register this redirect URI in Google Cloud Console:
        <code class="block my-1.5 rounded-md border border-amber-200 bg-white px-2 py-1.5 text-slate-800 break-all select-all"><?php echo e($redirect_uri); ?></code>
        Until then, use the temporary form below. It disappears once Google is configured.
        <form method="post" class="mt-3 grid gap-2">
          <input name="email" type="email" placeholder="Email" required autocomplete="username" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
          <input name="password" type="password" placeholder="Password" required autocomplete="current-password" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
          <button type="submit" class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 transition-colors py-2.5 text-sm font-semibold text-white">Temporary sign-in</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="drrmc-loader" id="drrmcLoader" role="status" aria-live="polite">
  <div class="spin-logo loading w-28 h-28 shadow-2xl shadow-black/40"><img src="assets/cdrrmd-logo.jpg" alt=""></div>
  <div class="drrmc-loader-text">Verifying your account&hellip;</div>
</div>
<script>
  (function () {
    var loader = document.getElementById('drrmcLoader');
    function showLoader() { loader.classList.add('active'); }
    document.getElementById('googleSignIn').addEventListener('click', showLoader);
    document.querySelectorAll('form').forEach(function (f) { f.addEventListener('submit', showLoader); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) loader.classList.remove('active'); });
  })();
</script>
</body>
</html>
