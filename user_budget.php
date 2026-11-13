<?php
require_once 'init.php';
require_login();
// Budget & Expenses for barangay staff has been removed from the system: send everyone to their dashboard.
header('Location: ' . dashboard_for_role(current_user_role())); exit;
require_once 'budget_lib.php';
require_once 'budget_ai.php';

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT id, fullname, barangay, status FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$barangay = trim((string)($me['barangay'] ?? ''));
$has_barangay = $barangay !== '';
$year = (int)($_GET['year'] ?? budget_fiscal_year());
if ($year < 2000 || $year > 2100) $year = budget_fiscal_year();
$schema_ok = budget_schema_ready($conn);

$flash = $_SESSION['budget_flash'] ?? null;
unset($_SESSION['budget_flash']);

if ($schema_ok && $has_barangay && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_expense') {
    $post_year = (int)($_POST['fiscal_year'] ?? $year);
    $revision = (int)($_POST['revision_of'] ?? 0);
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        $saved = budget_submit_packet($conn, $me, $post_year, $_FILES['excel_file'] ?? [], $_FILES['receipt_file'] ?? [], $revision > 0 ? $revision : null);
        if (!$saved['ok']) {
            $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => $saved['error']];
        } else {
            $check = budget_verify_upload($conn, $saved['id']);
            [$label] = budget_verdict_pill($check['verdict'] ?? '');
            $_SESSION['budget_flash'] = ['type' => 'ok', 'text' => 'Submitted as packet #' . $saved['id'] . '. AI check: ' . ($check['ok'] ? $label . ' (score ' . $check['score'] . '/100)' : 'could not be completed') . '. It is now waiting for review by a DRRMC Admin.'];
        }
    }
    header('Location: user_budget.php?year=' . $post_year);
    exit;
}

$bal = ($schema_ok && $has_barangay) ? budget_balances($conn, $year, $barangay) : [];
$b = $bal ? $bal[0] : ['allocated' => 0, 'spent' => 0, 'pending_review' => 0, 'remaining' => 0];
$funded = (float)$b['allocated'] > 0;
$packets = ($schema_ok && $has_barangay) ? budget_packets($conn, $barangay, null, 40) : [];
$notices = $schema_ok ? budget_notices($conn, $user_id, 8) : [];
$unread = [];
foreach ($notices as $n) if ($n['read_at'] === null) $unread[$n['id']] = true;
if ($unread) budget_mark_notices_read($conn, $user_id);
$years = $schema_ok ? budget_years($conn) : [$year];
if (!in_array($year, $years, true)) { $years[] = $year; rsort($years); }
$used = (float)$b['allocated'] > 0 ? min(100, (float)$b['spent'] / (float)$b['allocated'] * 100) : 0;
$ai_ready = ai_ocr_configured();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Budget &amp; Expenses</title>
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
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-peso-sign" style="color:var(--primary);margin-right:8px;"></i>Budget &amp; Expenses</h2>
      <p style="margin:4px 0 0;color:#666;font-size:13px;"><?= $has_barangay ? e(barangay_label($barangay)) . ' &middot; ' : '' ?>Your allocated budget, and how to report what you spent.</p>
    </header>
    <main class="main-content">
      <div class="bud-page">
        <?php if ($flash): ?><div class="bud-banner <?= e($flash['type']) ?>"><i class="fa-solid <?= $flash['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= e($flash['text']) ?></div></div><?php endif; ?>
        <?php if (!$schema_ok): ?><div class="bud-banner danger"><i class="fa-solid fa-database"></i><div>The budget module is not set up yet. Please contact the DRRMC office.</div></div><?php endif; ?>
        <?php if (!$has_barangay): ?><div class="bud-banner danger"><i class="fa-solid fa-location-dot"></i><div>Your account has no barangay assigned yet, so no budget can be shown. Please contact the DRRMC office.</div></div><?php endif; ?>

        <div class="bud-toolbar">
          <form method="get" style="display:flex;align-items:center;gap:8px;">
            <label for="yearSel" style="font-size:13px;font-weight:700;color:#344054;">Fiscal year</label>
            <select id="yearSel" name="year" onchange="this.form.submit()"><?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $y === $year ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select>
          </form>
        </div>

        <div class="bud-stats">
          <div class="bud-stat"><span>Allocated to your barangay</span><strong><?= e(peso($b['allocated'])) ?></strong><small>set by the City DRRMC Head</small></div>
          <div class="bud-stat good"><span>Spent (approved)</span><strong><?= e(peso($b['spent'])) ?></strong><small><?= number_format($used, 1) ?>% of budget used</small></div>
          <div class="bud-stat warn"><span>Pending review</span><strong><?= e(peso($b['pending_review'])) ?></strong><small>waiting for a DRRMC Admin</small></div>
          <div class="bud-stat <?= (float)$b['remaining'] < 0 ? 'bad' : '' ?>"><span>Remaining</span><strong><?= e(peso($b['remaining'])) ?></strong><small>available to spend</small></div>
        </div>

        <div class="settings-grid" style="align-items:start;">
          <section class="settings-card">
            <div class="settings-card-head">
              <span class="settings-icon"><i class="fa-solid fa-bell"></i></span>
              <div class="settings-head-text"><h3>Budget notices</h3><p>Sent automatically when your budget is allocated or adjusted</p></div>
            </div>
            <div class="settings-card-body">
              <?php if ($notices): foreach ($notices as $n): ?>
                <div class="bud-notice"><i class="fa-solid fa-<?= isset($unread[$n['id']]) ? 'circle-dot' : 'circle-check' ?>"></i>
                  <div><?= e($n['message']) ?><?php if (isset($unread[$n['id']])): ?> <span class="pill blue">New</span><?php endif; ?><small><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></small></div></div>
              <?php endforeach; else: ?><p class="settings-empty">No budget notices yet. You will be notified here as soon as the City DRRMC Head allocates your budget.</p><?php endif; ?>
            </div>
          </section>

          <section class="settings-card" id="submitCard">
            <div class="settings-card-head">
              <span class="settings-icon"><i class="fa-solid fa-file-invoice-dollar"></i></span>
              <div class="settings-head-text"><h3>Report an expense</h3><p>Upload two files. Nothing to type: the amounts are read from your files.</p></div>
            </div>
            <div class="settings-card-body">
              <?php if ($schema_ok && $has_barangay && $funded): ?>
              <?php if (!$ai_ready): ?><div class="bud-banner info" style="margin-bottom:14px;"><i class="fa-solid fa-circle-info"></i><div>Receipt reading (AI) is not switched on for this server, so submissions will be marked “Unreadable” and a DRRMC Admin can only return them. Ask the system administrator to add the AI key.</div></div><?php endif; ?>
              <form method="post" enctype="multipart/form-data" id="expenseForm">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="submit_expense">
                <input type="hidden" name="fiscal_year" value="<?= (int)$year ?>">
                <input type="hidden" name="revision_of" id="revisionOf" value="">
                <div id="revisionNote" class="bud-banner info" style="display:none;margin-bottom:14px;"><i class="fa-solid fa-rotate"></i><div>Re-submitting packet <strong id="revisionNo"></strong> that was returned. <a href="#" id="revisionCancel">Cancel</a></div></div>
                <div class="settings-field"><label for="excel_file">1. Purchase breakdown (Excel .xlsx or .csv)</label>
                  <input id="excel_file" name="excel_file" type="file" accept=".xlsx,.csv" required>
                  <p class="settings-help">Columns: Item, Quantity, Unit Cost, Amount. One row per item.</p></div>
                <div class="settings-field"><label for="receipt_file">2. Official receipt or invoice (photo or PDF)</label>
                  <input id="receipt_file" name="receipt_file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                  <p class="settings-help">A clear, complete photo or scan of the official receipt.</p></div>
                <div class="settings-actions"><button type="submit" class="btn">Submit for AI check &amp; review</button></div>
              </form>
              <?php elseif ($schema_ok && $has_barangay): ?>
                <div class="bud-empty" style="padding:24px 0;"><i class="fa-solid fa-lock"></i>You can report expenses once the City DRRMC Head has allocated a budget to your barangay for FY <?= (int)$year ?>.</div>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <section class="settings-card" style="margin-top:20px;">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
            <div class="settings-head-text"><h3>My submissions</h3><p>Every packet you sent, its AI result and the admin’s decision</p></div>
          </div>
          <?php if ($packets): ?>
          <div class="settings-table-wrap"><table class="bud-table">
            <thead><tr><th>Packet</th><th>Submitted</th><th class="num">Receipt total</th><th>AI check</th><th>Status</th><th>Admin remarks</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($packets as $p): [$slabel, $sclass] = budget_status_pill($p['status']); [$vlabel, $vclass] = budget_verdict_pill($p['verdict']);
                $flags = []; if ($p['ai_log_id']) { $r = $conn->query('SELECT flags FROM tbl_ai_verification_log WHERE id = ' . (int)$p['ai_log_id']); $flags = json_decode(($r->fetch_assoc()['flags'] ?? '[]'), true) ?: []; } ?>
              <tr>
                <td><strong>#<?= (int)$p['upload_id'] ?></strong><?= $p['revision_of'] ? '<br><small style="color:#98a2b3;">revises #' . (int)$p['revision_of'] . '</small>' : '' ?><br><small style="color:#98a2b3;">FY <?= (int)$p['fiscal_year'] ?></small></td>
                <td style="white-space:nowrap;"><?= e(date('M j, Y g:i A', strtotime($p['uploaded_at']))) ?></td>
                <td class="num"><?= $p['receipt_total'] !== null ? e(peso($p['receipt_total'])) : '<span style="color:#98a2b3">-</span>' ?></td>
                <td><span class="pill <?= $vclass ?>"><?= e($vlabel) ?></span><?= ($p['match_score'] !== null && $p['verdict'] !== 'unreadable') ? ' <small style="color:#667085;">' . (int)$p['match_score'] . '/100</small>' : '' ?>
                  <?php if ($flags): ?><details style="margin-top:6px;"><summary style="cursor:pointer;font-size:12px;color:#175cd3;">What did the AI find?</summary><ul style="margin:6px 0 0;padding-left:18px;font-size:12px;color:#475467;"><?php foreach ($flags as $f): ?><li><?= e($f['detail'] ?? $f['code'] ?? '') ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
                <td><span class="pill <?= $sclass ?>"><?= e($slabel) ?></span></td>
                <td style="max-width:260px;"><?= $p['decision_remarks'] ? e($p['decision_remarks']) : '<span style="color:#98a2b3">-</span>' ?></td>
                <td style="white-space:nowrap;">
                  <a class="btn-ghost btn-sm" href="budget_file.php?id=<?= (int)$p['upload_id'] ?>&f=receipt" target="_blank" rel="noopener">Receipt</a>
                  <?php if ($p['status'] === 'returned'): ?><a href="#submitCard" class="btn-ghost btn-sm" data-resubmit="<?= (int)$p['upload_id'] ?>">Resubmit</a><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php else: ?><div class="bud-empty"><i class="fa-solid fa-folder-open"></i>You have not submitted any expenses yet.</div><?php endif; ?>
        </section>
      </div>
    </main>
  </div>
</div>
<div class="bud-busy" id="busy"><i class="fa-solid fa-spinner fa-spin"></i><div>Uploading and checking your files with AI...</div><small style="font-weight:400;color:#667085;">This can take up to a minute. Please keep this page open.</small></div>
<script>
(function () {
  var form = document.getElementById('expenseForm');
  if (form) form.addEventListener('submit', function () { document.getElementById('busy').classList.add('open'); });
  var note = document.getElementById('revisionNote'), field = document.getElementById('revisionOf');
  [].forEach.call(document.querySelectorAll('[data-resubmit]'), function (a) {
    a.addEventListener('click', function () {
      if (!field) return;
      field.value = a.getAttribute('data-resubmit');
      document.getElementById('revisionNo').textContent = '#' + field.value;
      note.style.display = '';
    });
  });
  var cancel = document.getElementById('revisionCancel');
  if (cancel) cancel.addEventListener('click', function (e) { e.preventDefault(); field.value = ''; note.style.display = 'none'; });
})();
</script>
</body>
</html>
