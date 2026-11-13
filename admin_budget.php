<?php
require_once 'init.php';
require_login();
// The budget module has been removed from the system for every role: send everyone to their dashboard.
header('Location: ' . dashboard_for_role(current_user_role())); exit;
require_once 'budget_lib.php';

$is_head = is_role_in(['head_admin']);
$user_id = (int)$_SESSION['user_id'];
$year = (int)($_GET['year'] ?? budget_fiscal_year());
if ($year < 2000 || $year > 2100) $year = budget_fiscal_year();
$ledger_brgy = isset($_GET['brgy']) && preg_match('/^\d{1,3}$/', $_GET['brgy']) ? $_GET['brgy'] : null;

$flash = $_SESSION['budget_flash'] ?? null;
unset($_SESSION['budget_flash']);
$schema_ok = budget_schema_ready($conn);

if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'admin_budget.php?year=' . $year;
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } elseif (!$is_head) {
        // Admins can look, never write. The database would refuse too; this just makes the attempt visible.
        audit_log('BUDGET_WRITE_DENIED', 'budget ledger', 'A DRRMC Admin attempted to change budget figures', 'Failed');
        $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Only the City DRRMC Head can change budget figures. This attempt was logged.'];
    } elseif (($_POST['action'] ?? '') === 'allocate') {
        $barangay = trim((string)($_POST['barangay'] ?? ''));
        $post_year = (int)($_POST['fiscal_year'] ?? 0);
        $amount = budget_parse_amount($_POST['amount'] ?? '');
        if (!ctype_digit($barangay) || (int)$barangay < 1 || (int)$barangay > 188) {
            $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Choose a barangay.'];
        } elseif ($post_year < 2000 || $post_year > 2100) {
            $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Choose a valid fiscal year.'];
        } elseif ($amount === null || $amount[0] === '-') {
            $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Enter the amount in pesos, for example 250,000.00.'];
        } else {
            $res = budget_allocate($conn, $user_id, (string)(int)$barangay, $post_year, $amount);
            if ($res['ok']) {
                $_SESSION['budget_flash'] = ['type' => 'ok', 'text' => 'Allocated ' . peso($amount) . ' to ' . barangay_label($barangay) . ' for FY ' . $post_year . '. Notified ' . $res['staff'] . ' barangay staff account(s) and ' . $res['admins'] . ' DRRMC Admin(s); the entry is recorded in the audit log (ledger #' . $res['id'] . ').'];
                $back = 'admin_budget.php?year=' . $post_year;
            } else {
                $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => $res['message']];
            }
        }
    } elseif (($_POST['action'] ?? '') === 'adjust') {
        $adjusts = (int)($_POST['adjusts_id'] ?? 0);
        $amount = budget_parse_amount($_POST['amount'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? ''));
        $row = null;
        if ($adjusts > 0) {
            $stmt = $conn->prepare('SELECT barangay, fiscal_year FROM tbl_budget_allocation WHERE id = ?');
            $stmt->bind_param('i', $adjusts);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$row) {
            $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Choose the entry you are correcting.'];
        } elseif ($amount === null) {
            $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Enter the adjustment in pesos (use a minus sign to reduce), for example -5,000.00.'];
        } else {
            $res = budget_allocate($conn, $user_id, (string)$row['barangay'], (int)$row['fiscal_year'], $amount, 'adjustment', $adjusts, $reason);
            if ($res['ok']) {
                $_SESSION['budget_flash'] = ['type' => 'ok', 'text' => 'Adjustment of ' . peso($amount) . ' recorded for ' . barangay_label($row['barangay']) . ' (ledger #' . $res['id'] . '). Barangay staff and DRRMC Admins were notified.'];
                $back = 'admin_budget.php?year=' . (int)$row['fiscal_year'];
            } else {
                $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => $res['message']];
            }
        }
    }
    header('Location: ' . $back);
    exit;
}

$balances = $schema_ok ? budget_balances($conn, $year) : [];
$totals = ['allocated' => 0, 'spent' => 0, 'pending' => 0, 'remaining' => 0];
foreach ($balances as $b) {
    $totals['allocated'] += (float)$b['allocated'];
    $totals['spent'] += (float)$b['spent'];
    $totals['pending'] += (float)$b['pending_review'];
    $totals['remaining'] += (float)$b['remaining'];
}
$ledger = $schema_ok ? budget_ledger($conn, $year, 40, $ledger_brgy) : [];
$adjustable = [];
if ($schema_ok && $is_head) {
    $stmt = $conn->prepare("SELECT id, barangay, amount FROM tbl_budget_allocation WHERE fiscal_year = ? AND entry_type = 'allocation' ORDER BY CAST(barangay AS UNSIGNED), id");
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $adjustable = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$tampered = $schema_ok ? budget_tampered_rows($conn) : 0;
$years = $schema_ok ? budget_years($conn) : [$year];
if (!in_array($year, $years, true)) { $years[] = $year; rsort($years); }
$notices = (!$is_head && $schema_ok) ? budget_notices($conn, $user_id, 6) : [];
$notice_ids_unread = [];
foreach ($notices as $n) if ($n['read_at'] === null) $notice_ids_unread[$n['id']] = true;
if ($notices && $notice_ids_unread) budget_mark_notices_read($conn, $user_id);

$staff_counts = [];
if ($schema_ok && $balances) {
    $r = $conn->query("SELECT barangay, COUNT(*) AS n FROM users WHERE role = 'user' AND status = 'approved' GROUP BY barangay");
    while ($row = $r->fetch_assoc()) $staff_counts[$row['barangay']] = (int)$row['n'];
}
$page_title = $is_head ? 'Budget Allocation' : 'Barangay Budgets';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= e($page_title) ?></title>
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
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-peso-sign" style="color:var(--primary);margin-right:8px;"></i><?= e($page_title) ?></h2>
      <p style="margin:4px 0 0;color:#666;font-size:13px;"><?= $is_head ? 'Allocate each barangay’s budget. Every entry is permanent, time-stamped, and instantly notified to the barangay and to the DRRMC Admins.' : 'Read-only view of what the City DRRMC Head allocated to each barangay, and how much has been spent.' ?></p>
    </header>
    <main class="main-content" style="flex:1;overflow-y:auto;">
      <div class="bud-page">
        <?php if ($flash): ?><div class="bud-banner <?= e($flash['type']) ?>"><i class="fa-solid <?= $flash['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= e($flash['text']) ?></div></div><?php endif; ?>
        <?php if (!$schema_ok): ?><div class="bud-banner danger"><i class="fa-solid fa-database"></i><div>The budget tables are not installed yet. Import <strong>budget_transparency.sql</strong> into the database.</div></div><?php endif; ?>
        <?php if ($tampered > 0): ?><div class="bud-banner danger"><i class="fa-solid fa-triangle-exclamation"></i><div><strong>Integrity warning.</strong> <?= (int)$tampered ?> stored budget or AI record(s) no longer match their tamper-proof hash. Someone may have altered the database directly. Report this to the system administrator.</div></div><?php endif; ?>
        <?php if (!$is_head): ?><div class="bud-banner info"><i class="fa-solid fa-lock"></i><div>Only the <strong>City DRRMC Head</strong> can enter or change budget figures. You can view them here.</div></div><?php endif; ?>

        <div class="bud-toolbar">
          <form method="get" style="display:flex;align-items:center;gap:8px;">
            <label for="yearSel" style="font-size:13px;font-weight:700;color:#344054;">Fiscal year</label>
            <select id="yearSel" name="year" onchange="this.form.submit()"><?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $y === $year ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select>
          </form>
        </div>

        <div class="bud-stats">
          <div class="bud-stat"><span>Total allocated</span><strong><?= e(peso($totals['allocated'])) ?></strong><small><?= count($balances) ?> barangay<?= count($balances) === 1 ? '' : 's' ?> funded</small></div>
          <div class="bud-stat good"><span>Spent (approved)</span><strong><?= e(peso($totals['spent'])) ?></strong><small>counted from verified receipts</small></div>
          <div class="bud-stat warn"><span>Pending review</span><strong><?= e(peso($totals['pending'])) ?></strong><small>submitted, not yet decided</small></div>
          <div class="bud-stat"><span>Remaining</span><strong><?= e(peso($totals['remaining'])) ?></strong><small>allocated minus spent</small></div>
        </div>

        <?php if ($is_head && $schema_ok): ?>
        <section class="settings-card" style="margin-bottom:20px;">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-hand-holding-dollar"></i></span>
            <div class="settings-head-text"><h3>Allocate budget</h3><p>Recorded permanently. Barangay staff and DRRMC Admins are notified with the exact amount.</p></div>
          </div>
          <div class="settings-card-body">
            <form method="post" id="allocForm" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="allocate">
              <div class="bud-form-grid">
                <div class="settings-field"><label for="a_barangay">Barangay</label>
                  <select id="a_barangay" name="barangay" required><option value="">Select barangay</option><?= barangay_options_html('') ?></select></div>
                <div class="settings-field"><label for="a_year">Fiscal year</label>
                  <select id="a_year" name="fiscal_year"><?php for ($y = budget_fiscal_year() - 1; $y <= budget_fiscal_year() + 1; $y++): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
                <div class="settings-field"><label for="a_amount">Amount (PHP)</label>
                  <div class="bud-amount"><b>₱</b><input id="a_amount" name="amount" inputmode="decimal" placeholder="0.00" required></div></div>
                <div class="settings-actions" style="margin:0;"><button type="submit" class="btn">Allocate &amp; notify</button></div>
              </div>
            </form>
          </div>
        </section>
        <?php endif; ?>

        <section class="settings-card" style="margin-bottom:20px;">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-table-list"></i></span>
            <div class="settings-head-text"><h3>Budget by barangay - FY <?= (int)$year ?></h3><p>Allocated, spent and remaining for every funded barangay</p></div>
          </div>
          <?php if ($balances): ?>
          <div class="settings-table-wrap"><table class="bud-table">
            <thead><tr><th>Barangay</th><th class="num">Allocated</th><th class="num">Spent</th><th class="num">Pending</th><th class="num">Remaining</th><th style="min-width:130px;">Used</th><th class="num">Staff</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($balances as $b):
                $alloc = (float)$b['allocated']; $used = $alloc > 0 ? min(100, (float)$b['spent'] / $alloc * 100) : 0;
                $bar = $used >= 90 ? 'bad' : ($used >= 70 ? 'warn' : ''); ?>
              <tr>
                <td><strong><?= e(barangay_label($b['barangay'])) ?></strong></td>
                <td class="num"><?= e(peso($b['allocated'])) ?></td>
                <td class="num"><?= e(peso($b['spent'])) ?></td>
                <td class="num"><?= (float)$b['pending_review'] > 0 ? e(peso($b['pending_review'])) : '<span style="color:#98a2b3">-</span>' ?></td>
                <td class="num <?= (float)$b['remaining'] < 0 ? 'bud-neg' : '' ?>"><strong><?= e(peso($b['remaining'])) ?></strong></td>
                <td><div class="bud-bar <?= $bar ?>" title="<?= number_format($used, 1) ?>% used"><i style="width:<?= number_format($used, 1, '.', '') ?>%"></i></div></td>
                <td class="num"><?= (int)($staff_counts[$b['barangay']] ?? 0) ?></td>
                <td><a class="btn-ghost btn-sm" href="?year=<?= (int)$year ?>&brgy=<?= urlencode($b['barangay']) ?>#ledger">History</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php else: ?>
          <div class="bud-empty"><i class="fa-solid fa-coins"></i>No budget has been allocated for FY <?= (int)$year ?> yet.<?= $is_head ? ' Use “Allocate budget” above to fund the first barangay.' : '' ?></div>
          <?php endif; ?>
        </section>

        <?php if (!$is_head): ?>
        <section class="settings-card" style="margin-bottom:20px;">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-bell"></i></span>
            <div class="settings-head-text"><h3>Audit notices</h3><p>Sent automatically whenever the Head allocates or adjusts a budget</p></div>
          </div>
          <div class="settings-card-body">
            <?php if ($notices): foreach ($notices as $n): ?>
              <div class="bud-notice"><i class="fa-solid fa-<?= isset($notice_ids_unread[$n['id']]) ? 'circle-dot' : 'circle-check' ?>"></i>
                <div><?= e($n['message']) ?><?php if (isset($notice_ids_unread[$n['id']])): ?> <span class="pill blue">New</span><?php endif; ?><small><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></small></div></div>
            <?php endforeach; else: ?><p class="settings-empty">No budget notices yet.</p><?php endif; ?>
          </div>
        </section>
        <?php endif; ?>

        <section class="settings-card" id="ledger" style="margin-bottom:20px;">
          <div class="settings-card-head">
            <span class="settings-icon"><i class="fa-solid fa-book"></i></span>
            <div class="settings-head-text"><h3>Ledger<?= $ledger_brgy !== null ? ' - ' . e(barangay_label($ledger_brgy)) : '' ?></h3><p>Every allocation and adjustment, newest first. Entries can never be edited or deleted.</p></div>
            <?php if ($ledger_brgy !== null): ?><a class="btn-ghost btn-sm" href="?year=<?= (int)$year ?>#ledger">Show all</a><?php endif; ?>
          </div>
          <?php if ($ledger): ?>
          <div class="settings-table-wrap"><table class="bud-table">
            <thead><tr><th>Recorded</th><th>Barangay</th><th>Type</th><th class="num">Amount</th><th>Reason</th><th>By</th><th>Integrity</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): $good = $l['link_ok'] && $l['hash_ok']; ?>
              <tr>
                <td style="white-space:nowrap;"><?= e(date('M j, Y g:i A', strtotime($l['allocated_at']))) ?><br><small style="color:#98a2b3;">ledger #<?= (int)$l['id'] ?></small></td>
                <td><?= e(barangay_label($l['barangay'])) ?></td>
                <td><span class="pill <?= $l['entry_type'] === 'adjustment' ? 'amber' : 'green' ?>"><?= $l['entry_type'] === 'adjustment' ? 'Adjustment' : 'Allocation' ?></span></td>
                <td class="num <?= (float)$l['amount'] < 0 ? 'bud-neg' : 'bud-pos' ?>"><strong><?= (float)$l['amount'] > 0 ? '+' : '' ?><?= e(peso($l['amount'])) ?></strong></td>
                <td style="max-width:280px;"><?= $l['reason'] ? e($l['reason']) : '<span style="color:#98a2b3">-</span>' ?></td>
                <td><?= e($l['head_name']) ?></td>
                <td><span class="pill <?= $good ? 'green' : 'red' ?>" title="hash <?= e(substr($l['row_hash'], 0, 16)) ?>..."><i class="fa-solid <?= $good ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> <?= $good ? 'Intact' : 'Altered' ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php else: ?><div class="bud-empty"><i class="fa-solid fa-book-open"></i>No ledger entries yet.</div><?php endif; ?>
        </section>

        <?php if ($is_head && $schema_ok && $adjustable): ?>
        <details class="settings-card" style="margin-bottom:20px;">
          <summary class="settings-card-head" style="cursor:pointer;list-style:none;">
            <span class="settings-icon"><i class="fa-solid fa-pen-ruler"></i></span>
            <div class="settings-head-text"><h3>Correct an allocation</h3><p>Entries cannot be edited. Post a signed adjustment with a reason instead.</p></div>
            <i class="fa-solid fa-chevron-down" style="color:#98a2b3;"></i>
          </summary>
          <div class="settings-card-body">
            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="adjust">
              <div class="bud-form-grid" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);margin-bottom:14px;">
                <div class="settings-field"><label for="j_entry">Entry being corrected</label>
                  <select id="j_entry" name="adjusts_id" required><option value="">Select an allocation</option>
                    <?php foreach ($adjustable as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e(barangay_label($a['barangay'])) ?> - <?= e(peso($a['amount'])) ?> (#<?= (int)$a['id'] ?>)</option><?php endforeach; ?></select></div>
                <div class="settings-field"><label for="j_amount">Adjustment (PHP, minus to reduce)</label>
                  <div class="bud-amount"><b>₱</b><input id="j_amount" name="amount" inputmode="decimal" placeholder="-5,000.00" required></div></div>
              </div>
              <div class="settings-field"><label for="j_reason">Reason (required, recorded in the ledger)</label><input id="j_reason" name="reason" minlength="10" maxlength="500" placeholder="e.g. Reallocated to Barangay 6 after typhoon damage assessment" required></div>
              <div class="settings-actions"><button type="submit" class="btn">Record adjustment &amp; notify</button></div>
            </form>
          </div>
        </details>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<?php if ($is_head): ?>
<div class="bud-modal" id="allocModal" role="dialog" aria-modal="true">
  <div class="bud-modal-box">
    <h3>Confirm allocation</h3>
    <p>You are about to allocate:</p>
    <div class="big" id="confirmAmount">₱0.00</div>
    <p><strong id="confirmBrgy"></strong> &middot; FY <span id="confirmYear"></span></p>
    <p>This is recorded permanently with your name and the exact time. It <strong>cannot be edited or deleted</strong> (only corrected by a later adjustment). Barangay staff and all DRRMC Admins will be notified with this amount.</p>
    <div class="bud-modal-actions"><button type="button" class="btn-ghost" id="confirmCancel">Cancel</button><button type="button" class="btn" id="confirmOk">Yes, allocate</button></div>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('allocForm'); if (!form) return;
  var amount = document.getElementById('a_amount'), modal = document.getElementById('allocModal'), confirmed = false;
  function clean(v) { return v.replace(/[^\d.]/g, ''); }
  amount.addEventListener('input', function () {
    var v = clean(amount.value), parts = v.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    amount.value = parts.length > 1 ? parts[0] + '.' + parts.slice(1).join('').slice(0, 2) : parts[0];
  });
  form.addEventListener('submit', function (e) {
    if (confirmed) return;
    e.preventDefault();
    var n = parseFloat(clean(amount.value));
    if (!(n > 0)) { amount.focus(); return; }
    var sel = document.getElementById('a_barangay');
    document.getElementById('confirmAmount').textContent = '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('confirmBrgy').textContent = sel.options[sel.selectedIndex].text;
    document.getElementById('confirmYear').textContent = document.getElementById('a_year').value;
    modal.classList.add('open');
  });
  document.getElementById('confirmCancel').addEventListener('click', function () { modal.classList.remove('open'); });
  document.getElementById('confirmOk').addEventListener('click', function () { confirmed = true; this.disabled = true; form.submit(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.classList.remove('open'); });
})();
</script>
<?php endif; ?>
</body>
</html>
