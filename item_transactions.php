<?php
// Barangay staff: record items released (check-out) or obtained (check-in). Each one updates the Main Inventory
// and is written to the Release & Obtained log in one database transaction.
require_once 'init.php';
require_login();
if (is_role_in(['head_admin', 'admin', 'official'])) { header('Location: admin_dashboard.php'); exit; }
require_once 'item_tx_lib.php';
require_once 'expiry_lib.php';

$user_id = (int)$_SESSION['user_id'];
$st = $conn->prepare('SELECT fullname, barangay FROM users WHERE id = ? LIMIT 1');
$st->bind_param('i', $user_id);
$st->execute();
$me = $st->get_result()->fetch_assoc() ?: [];
$st->close();
$brgy = (int)preg_replace('/\D+/', '', (string)($me['barangay'] ?? ''));

$flash = $_SESSION['tx_flash'] ?? null; unset($_SESSION['tx_flash']);
$form = ['type' => 'released', 'item' => '', 'qty' => '', 'counterpart' => '', 'remarks' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_tx'])) {
    $form = ['type' => (string)($_POST['txn_type'] ?? ''), 'item' => (string)($_POST['item_key'] ?? ''), 'qty' => (string)($_POST['quantity'] ?? ''),
        'counterpart' => (string)($_POST['counterpart'] ?? ''), 'remarks' => (string)($_POST['remarks'] ?? '')];
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } else {
        // A medical supply coming in needs its expiry date (expiry tracking by batch).
        [$batch, $berr] = exp_batch_from_post($form['type'], $form['item']);
        $r = $berr ? ['ok' => false, 'error' => $berr] : item_tx_record($user_id, (string)($me['fullname'] ?? 'Barangay staff'), $brgy, $form['type'], $form['item'], $form['qty'], $form['counterpart'], $form['remarks'], $batch);
        if (!empty($_POST['return'])) {
            // Saved from Main Inventory: go back there and show the result.
            $cat = in_array($_POST['inventory_category'] ?? '', ['Tools & Equipment', 'Medical Supplies'], true) ? $_POST['inventory_category'] : 'Tools & Equipment';
            $_SESSION['tx_flash_dashboard'] = ['ok' => $r['ok'], 'cat' => $cat, 'text' => $r['ok'] ? $r['code'] . ' saved to the Audit Logs: ' . ($form['type'] === 'released' ? 'Item Out' : 'Item In') . ', ' . (int)$form['qty'] . ' ' . $r['label'] . '. Stock is now ' . $r['after'] . ' (was ' . $r['before'] . ').' : $r['error']];
            header('Location: user_dashboard.php');
            exit;
        }
        if ($r['ok']) {
            // post/redirect/get: refreshing the page never records the transaction twice
            $_SESSION['tx_flash'] = ['ok', $r['code'] . ' saved: ' . ($form['type'] === 'released' ? 'Item Out' : 'Item In') . ', ' . (int)$form['qty'] . ' ' . $r['label'] . '. Stock is now ' . $r['after'] . ' (was ' . $r['before'] . ').'];
            header('Location: item_transactions.php');
            exit;
        }
        $flash = ['err', $r['error']];
    }
}
$report = item_tx_latest_report($brgy);
$catalog = item_tx_catalog();
$filters = ['q' => $_GET['q'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'type' => $_GET['type'] ?? '', 'barangay' => $brgy];
$rows = $brgy ? item_tx_search($filters) : [];
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
    .tx-form { display:grid !important; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; align-items:end; }
    .tx-form label { display:grid; gap:5px; margin:0; font-size:12px; font-weight:700; color:#344054; }
    .tx-form input, .tx-form select, .tx-form textarea { margin:0; width:100%; box-sizing:border-box; padding:10px; border:1px solid #d0d5dd; border-radius:8px; font:inherit; font-size:14px; text-transform:none; }
    .tx-form .wide { grid-column:1 / -1; } .tx-form .half { grid-column:span 2; }
    .tx-kind { display:flex; gap:8px; grid-column:1 / -1; }
    .tx-kind label { flex:1; display:flex !important; align-items:center; gap:10px; padding:14px; border:2px solid #e4e7ec; border-radius:12px; cursor:pointer; font-size:15px; text-transform:none; letter-spacing:0; }
    .tx-kind input { width:auto; accent-color:#ea7a00; }
    .tx-kind label:has(input:checked) { border-color:#ea7a00; background:#fff7ed; }
    .tx-kind small { display:block; font-weight:400; color:#667085; font-size:12px; }
    .tx-stock { font-size:13px; color:#475467; }
    .tx-stock b { color:#1f2937; }
    .fc-flash { padding:12px 16px; border-radius:10px; font-size:14px; } .fc-flash.ok { background:#ecfdf3; color:#05603a; border:1px solid #abefc6; } .fc-flash.err { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }
    .tx-tabs { display:flex; gap:8px; padding:6px; background:rgba(255,255,255,.9); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
    .tx-tab { flex:1; display:flex; align-items:center; justify-content:center; gap:8px; margin:0; padding:13px; border:0; border-radius:10px; background:transparent; color:#344054; font:inherit; font-size:15px; font-weight:800; cursor:pointer; }
    .tx-tab:hover { background:#fff7ed; color:#9a4a00; }
    .tx-tab.active { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; box-shadow:0 4px 12px rgba(234,122,0,.3); }
    .tx-tab-n { min-width:22px; padding:1px 7px; border-radius:999px; background:#f2f4f7; color:#475467; font-size:12px; }
    .tx-tab.active .tx-tab-n { background:rgba(255,255,255,.3); color:#fff; }
    .tx-card[hidden], .tx-tabs[hidden] { display:none !important; }
    @media (max-width:900px) { .tx-form { grid-template-columns:1fr 1fr; } }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-right-left" style="color:var(--primary);margin-right:8px;"></i>Audit Logs</h2>
      <p style="margin:4px 0 0;color:#667085;font-size:13px;">Every item released or obtained by <?= e(barangay_label($brgy)) ?>. To release or obtain an item, use the buttons in View Inventory &rarr; Main Inventory.</p>
    </header>
    <main class="main-content">
      <div class="tx-page">
        <?php if ($flash): ?><div class="fc-flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php $tx_view = 'log'; ?>
        <div class="tx-tabs" role="tablist" hidden>
          <button type="button" class="tx-tab<?= $tx_view === 'record' ? ' active' : '' ?>" data-tx-view="record"><i class="fa-solid fa-pen-to-square"></i> Record a Transaction</button>
          <button type="button" class="tx-tab<?= $tx_view === 'log' ? ' active' : '' ?>" data-tx-view="log"><i class="fa-solid fa-clock-rotate-left"></i> Audit Logs <b class="tx-tab-n"><?= count($rows) ?></b></button>
        </div>
        <section class="tx-card" data-tx-panel="record"<?= $tx_view === 'record' ? '' : ' hidden' ?>>
          <h3>Record a transaction</h3>
          <?php if (!$report): ?>
            <p>Your barangay has no equipment report yet. <a href="user_report.php">Submit an equipment report</a> first so there is a stock to update.</p>
          <?php else: ?>
          <p>Main Inventory = your latest equipment report (#<?= (int)$report['id'] ?>, submitted <?= e(date('M j, Y g:i A', strtotime($report['submitted_at']))) ?>).</p>
          <form method="post" class="tx-form" id="txForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_tx" value="1">
            <div class="tx-kind">
              <label><input type="radio" name="txn_type" value="released"<?= $form['type'] !== 'obtained' ? ' checked' : '' ?>><span><b>&#8593; Item Out</b><small>Item goes out: deducted from stock</small></span></label>
              <label><input type="radio" name="txn_type" value="obtained"<?= $form['type'] === 'obtained' ? ' checked' : '' ?>><span><b>&#8595; Item In</b><small>Item returned or newly acquired: added to stock</small></span></label>
            </div>
            <label class="half">Item
              <select name="item_key" id="txItem" required>
                <option value="">Choose an item...</option>
                <?php foreach (['Tools & Equipment', 'Medical Supplies'] as $cat): ?><optgroup label="<?= e($cat) ?>">
                  <?php foreach ($catalog as $k => [$l, $c]): if ($c !== $cat) continue; $raw = trim((string)($report[$k] ?? '')); $q = is_numeric($raw) ? (int)$raw : 0; ?>
                    <option value="<?= e($k) ?>" data-qty="<?= $q ?>"<?= $form['item'] === $k ? ' selected' : '' ?>><?= e($l) ?> (on hand: <?= $q ?>)</option>
                  <?php endforeach; ?></optgroup><?php endforeach; ?>
              </select>
            </label>
            <label>Quantity<input type="number" name="quantity" id="txQty" min="1" max="99999" step="1" required value="<?= e($form['qty']) ?>"></label>
            <div class="tx-stock" id="txStock">Choose an item to see the stock.</div>
            <div class="half" style="grid-column:1 / -1;"><?= exp_fields_html() ?></div>
            <label class="half">Date &amp; time <span style="font-weight:400;color:#667085;text-transform:none;letter-spacing:0;">(automatic)</span><input type="text" id="txNow" readonly tabindex="-1" style="background:#f4f6f8;color:#475467;" value="<?= e(date('l, F j, Y g:i:s A')) ?>"></label>
            <label class="half">Recorded by <span style="font-weight:400;color:#667085;text-transform:none;letter-spacing:0;">(automatic)</span><input type="text" readonly tabindex="-1" style="background:#f4f6f8;color:#475467;" value="<?= e($me['fullname'] ?? '') ?>"></label>
            <label class="half"><span id="txWhoLabel">Name of the person who took out the item</span><input name="counterpart" id="txWho" maxlength="150" value="<?= e($form['counterpart']) ?>" placeholder="e.g. Juan Dela Cruz"></label>
            <label class="half">Remarks / Purpose<input name="remarks" maxlength="500" required value="<?= e($form['remarks']) ?>" placeholder="e.g. Used during flood response at Block 5"></label>
            <div class="wide"><button type="submit" class="tx-btn" id="txSubmit"><i class="fa-solid fa-floppy-disk"></i> Save transaction</button></div>
          </form>
          <?php endif; ?>
        </section>
        <section class="tx-card" data-tx-panel="log"<?= $tx_view === 'log' ? '' : ' hidden' ?>>
          <h3>Audit Logs: Item Out &amp; Item In History</h3>
          <p>Every transaction of <?= e(barangay_label($brgy)) ?>. Entries are permanent and cannot be edited or deleted.</p>
          <?= item_tx_filters_html($filters, false) ?>
          <p class="tx-count"><?= count($rows) ?> transaction<?= count($rows) === 1 ? '' : 's' ?></p>
          <?= item_tx_table_html($rows, false) ?>
        </section>
      </div>
    </main>
  </div>
</div>
<?= item_tx_assets() ?>
<?= exp_fields_assets() ?>
<script>
(function () {
  document.querySelectorAll('[data-tx-view]').forEach(function (t) { t.addEventListener('click', function () {
    document.querySelectorAll('[data-tx-view]').forEach(function (x) { x.classList.toggle('active', x === t); });
    document.querySelectorAll('[data-tx-panel]').forEach(function (p) { p.hidden = p.dataset.txPanel !== t.dataset.txView; });
  }); });
  var form = document.getElementById('txForm'); if (!form) return;
  var item = document.getElementById('txItem'), qty = document.getElementById('txQty'), stock = document.getElementById('txStock'), who = document.getElementById('txWho'), whoLabel = document.getElementById('txWhoLabel');
  function type() { return form.querySelector('input[name=txn_type]:checked').value; }
  function update() {
    var rel = type() === 'released', opt = item.selectedOptions[0], have = opt && opt.value ? +opt.dataset.qty : null, n = parseInt(qty.value, 10) || 0;
    whoLabel.textContent = rel ? 'Name of the person who took out the item (required)' : 'Name of the person who returned / brought in the item (required)';
    who.placeholder = rel ? 'e.g. Juan Dela Cruz' : 'e.g. Juan Dela Cruz, or City DRRMO';
    who.required = true;
    qty.max = rel && have !== null ? Math.max(have, 1) : 99999;
    if (window.expFields) expFields(form, type(), item.value);
    if (have === null) { stock.textContent = 'Choose an item to see the stock.'; return; }
    var after = rel ? have - n : have + n;
    stock.innerHTML = 'On hand: <b>' + have + '</b>' + (n ? ' &rarr; after this: <b style="color:' + (after < 0 ? '#b42318' : '#1f2937') + '">' + after + '</b>' + (after < 0 ? ' (not enough stock)' : '') : '');
  }
  // Live clock: the exact date and time are saved automatically by the server when the transaction is saved.
  var nowEl = document.getElementById('txNow');
  setInterval(function () { nowEl.value = new Date().toLocaleString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }); }, 1000);
  form.addEventListener('change', update); qty.addEventListener('input', update);
  form.addEventListener('submit', function (e) { if (form.dataset.sent) { e.preventDefault(); return; } form.dataset.sent = '1'; });
  update();
})();
</script>
</body>
</html>
