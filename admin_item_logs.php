<?php
// DRRMC Officials and the City Head: complete Release & Obtained log of every barangay (read-only),
// with search, date range, type and barangay filters, and Excel / PDF export.
require_once 'init.php';
require_login();
// Release & Obtained is for barangay staff only; DRRMC officials and the City Head go back to their dashboard.
if (is_role_in(['head_admin', 'admin', 'official'])) { header('Location: admin_dashboard.php'); exit; }
require_role(['head_admin', 'admin', 'official']);
require_once 'item_tx_lib.php';

$filters = ['q' => $_GET['q'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'type' => $_GET['type'] ?? '', 'barangay' => $_GET['barangay'] ?? ''];
$rows = item_tx_search($filters, 2000);
$range = ($filters['from'] || $filters['to']) ? trim(($filters['from'] ? date('M j, Y', strtotime($filters['from'])) : 'start') . ' to ' . ($filters['to'] ? date('M j, Y', strtotime($filters['to'])) : 'today')) : 'All dates';

// Printable version (loaded in a hidden frame for "Export PDF").
if (isset($_GET['print'])) {
    audit_log('ITEM_LOG_EXPORTED', 'Item Out & Item In History', 'PDF, ' . count($rows) . ' row(s)');
    ?><!doctype html><html><head><meta charset="utf-8"><title>Item Out &amp; Item In History</title>
    <style>body{font-family:Arial,sans-serif;margin:24px;color:#000}h1{font-size:18px;margin:0 0 4px;text-align:center}p{margin:0 0 12px;text-align:center;font-size:11px}
    table{width:100%;border-collapse:collapse;font-size:9.5px}th,td{border:1px solid #000;padding:4px 5px;text-align:left;vertical-align:top}th{background:#eee}
    @page{size:landscape;margin:12mm}</style></head><body>
    <h1>RELEASE &amp; OBTAINED HISTORY</h1><p>City of Caloocan DRRM Office · <?= e($range) ?> · <?= count($rows) ?> transaction(s) · generated <?= e(date('M j, Y g:i A')) ?></p>
    <table><tr><th>Transaction ID</th><th>Date &amp; time</th><th>Barangay</th><th>Item</th><th>Category</th><th>Type</th><th>Qty</th><th>Stock</th><th>Taken out / Returned by</th><th>Processed by</th><th>Remarks / Purpose</th></tr>
    <?php foreach ($rows as $r): ?><tr><td><?= e(item_tx_code($r)) ?></td><td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td><td><?= e(barangay_label($r['barangay'])) ?></td><td><?= e($r['item_name']) ?></td><td><?= e($r['category']) ?></td><td><?= e(item_tx_type_label($r['txn_type'])) ?></td><td><?= ($r['txn_type'] === 'released' ? '-' : '+') . (int)$r['quantity'] ?></td><td><?= (int)$r['qty_before'] ?> &rarr; <?= (int)$r['qty_after'] ?></td><td><?= e($r['counterpart'] ?? '-') ?></td><td><?= e($r['processed_by_name']) ?></td><td><?= e($r['remarks']) ?></td></tr><?php endforeach; ?>
    </table></body></html><?php
    exit;
}
$released = array_sum(array_map(function ($r) { return $r['txn_type'] === 'released' ? (int)$r['quantity'] : 0; }, $rows));
$obtained = array_sum(array_map(function ($r) { return $r['txn_type'] === 'obtained' ? (int)$r['quantity'] : 0; }, $rows));
$export = array_map(function ($r) {
    return ['Transaction ID' => item_tx_code($r), 'Date & time' => date('Y-m-d H:i', strtotime($r['created_at'])), 'Barangay' => barangay_label($r['barangay']),
        'Item' => $r['item_name'], 'Category' => $r['category'], 'Type' => item_tx_type_label($r['txn_type']), 'Quantity' => (int)$r['quantity'],
        'Stock before' => (int)$r['qty_before'], 'Stock after' => (int)$r['qty_after'], 'Taken out / Returned by' => (string)($r['counterpart'] ?? ''),
        'Processed by' => $r['processed_by_name'], 'Remarks / Purpose' => $r['remarks']];
}, $rows);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Item Out &amp; Item In History</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .tx-kpis { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; }
    .tx-kpis div { background:#fff; border-radius:12px; padding:14px 18px; box-shadow:0 6px 18px rgba(120,60,0,.1); }
    .tx-kpis b { display:block; font-size:28px; color:#1f2937; } .tx-kpis span { font-size:13px; color:#667085; }
    .tx-kpis .out b { color:#b42318; } .tx-kpis .in b { color:#067647; }
    .tx-export { display:flex; gap:8px; justify-content:flex-end; margin:-6px 0 10px; }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-right-left" style="color:var(--primary);margin-right:8px;"></i>Item Out &amp; Item In History</h2>
      <p style="margin:4px 0 0;color:#667085;font-size:13px;">Every item released (check-out) or obtained (check-in) by the barangays. Entries are permanent: they cannot be edited or deleted.</p>
    </header>
    <main class="main-content">
      <div class="tx-page">
        <div class="tx-kpis">
          <div><b><?= count($rows) ?></b><span>transactions (<?= e($range) ?>)</span></div>
          <div class="out"><b>-<?= $released ?></b><span>units released / checked out</span></div>
          <div class="in"><b>+<?= $obtained ?></b><span>units obtained / checked in</span></div>
        </div>
        <section class="tx-card">
          <?= item_tx_filters_html($filters, true) ?>
          <div class="tx-export">
            <button type="button" class="tx-btn ghost" id="txExcel"<?= $rows ? '' : ' disabled' ?>><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button type="button" class="tx-btn ghost" id="txPdf"<?= $rows ? '' : ' disabled' ?>><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
          </div>
          <p class="tx-count"><?= count($rows) ?> transaction<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) >= 2000 ? ' (showing the latest 2,000: narrow the dates to see older ones)' : '' ?> · click an item to see its current stock</p>
          <?= item_tx_table_html($rows, true) ?>
        </section>
      </div>
    </main>
  </div>
</div>
<?= item_tx_assets() ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
(function () {
  var data = <?= json_encode($export, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var stamp = new Date().toISOString().slice(0, 10);
  document.getElementById('txExcel').addEventListener('click', function () {
    if (typeof XLSX === 'undefined') { alert('The spreadsheet tool did not load. Check the internet connection.'); return; }
    var ws = XLSX.utils.json_to_sheet(data);
    ws['!cols'] = [18, 17, 14, 26, 18, 22, 9, 12, 12, 26, 22, 40].map(function (w) { return { wch: w }; });
    var wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Item Out & Item In');
    XLSX.writeFile(wb, 'release-obtained-history-' + stamp + '.xlsx');
  });
  // PDF: the print view in a hidden frame -> the print dialog (choose "Save as PDF").
  document.getElementById('txPdf').addEventListener('click', function () {
    var old = document.getElementById('txPrintFrame'); if (old) old.remove();
    var f = document.createElement('iframe'); f.id = 'txPrintFrame';
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    f.src = 'admin_item_logs.php' + (location.search ? location.search + '&' : '?') + 'print=1';
    f.onload = function () { setTimeout(function () { f.contentWindow.focus(); f.contentWindow.print(); }, 300); };
    document.body.appendChild(f);
  });
})();
</script>
</body>
</html>
