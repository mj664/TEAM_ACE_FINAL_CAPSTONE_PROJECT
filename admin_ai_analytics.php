<?php
require_once 'init.php';
require_once __DIR__ . '/south_caloocan_map.php';
require_login();
require_role(['head_admin']); // Decision support is a Head Admin function

$status_rows = build_barangay_stock_status($conn);
$reported = array_values(array_filter($status_rows, function ($b) { return !empty($b['has_report']); }));
$critical = array_values(array_filter($reported, function ($b) { return ($b['level'] ?? '') === 'critical'; }));
$warning = array_values(array_filter($reported, function ($b) { return ($b['level'] ?? '') === 'warning'; }));
$optimal = array_values(array_filter($reported, function ($b) { return ($b['level'] ?? '') === 'optimal'; }));
$total_barangays = count($status_rows);
$coverage = $total_barangays ? (int)round(count($reported) / $total_barangays * 100) : 0;

// Supply label => key in the per-barangay supplies map (gloves are not tracked there).
$supply_keys = [
  'Facemask' => 'facemask', 'Elastic Bandage' => 'elastic_bandage', 'Betadine' => 'betadine_dropper',
  'Surgical Tape' => 'surgical_tape', 'Gauze Pad' => 'gauze_pad', 'Nasal Cannula' => 'nasal_cannula', 'Oxygen Mask' => 'oxygen_mask',
  'Gloves' => 'gloves',
];
// The map's supplies list has no gloves, so read gloves from each barangay's latest report here.
$gloves_by_brgy = [];
$gl = $conn->query("SELECT u.barangay, er.gloves FROM equipment_reports er
    INNER JOIN users u ON u.id = er.user_id
    INNER JOIN (SELECT user_id, MAX(submitted_at) AS latest FROM equipment_reports GROUP BY user_id) latest
      ON latest.user_id = er.user_id AND latest.latest = er.submitted_at
    WHERE u.status = 'approved' ORDER BY er.submitted_at DESC");
if ($gl) while ($g = $gl->fetch_assoc()) {
  $num = (int)preg_replace('/\D+/', '', (string)$g['barangay']);
  $raw = trim((string)$g['gloves']);
  if ($num && !array_key_exists($num, $gloves_by_brgy)) $gloves_by_brgy[$num] = ($raw === '' || !is_numeric($raw)) ? null : max(0, (int)$raw);
}
foreach ($reported as &$rb) $rb['supplies']['gloves'] = $gloves_by_brgy[(int)$rb['number']] ?? null;
unset($rb);

// Rule-based redistribution advisory. A supply is sufficient at 16 or more, so each critical shortage needs
// (16 - on hand) units. Barangays share only their extra units (everything above 16), and every unit a barangay
// gives away is taken out of what it can still give, so the same 50 facemasks are never promised twice.
const ADVISORY_TARGET = 16;
$spare = []; // item key => [barangay number => extra units it can still share]
foreach ($supply_keys as $key) {
  foreach ($reported as $b) {
    $qty = $b['supplies'][$key] ?? null;
    if ($qty !== null && $qty > ADVISORY_TARGET) $spare[$key][(int)$b['number']] = ['b' => $b, 'left' => $qty - ADVISORY_TARGET];
  }
}
$shortages = [];
foreach ($critical as $need) {
  foreach ($need['low_items'] as $item) {
    $key = $supply_keys[$item['name']] ?? null;
    if ($key) $shortages[] = ['need' => $need, 'item' => $item, 'key' => $key];
  }
}
// Lowest stock first, so the barangays closest to running out are served first.
usort($shortages, function ($a, $b) { return (int)$a['item']['quantity'] <=> (int)$b['item']['quantity'] ?: (int)$a['need']['number'] <=> (int)$b['need']['number']; });
$advisories = [];
foreach ($shortages as $s) {
  $needed = max(0, ADVISORY_TARGET - (int)$s['item']['quantity']);
  $left = $needed;
  $sources = [];
  while ($left > 0) {
    $best = null;
    foreach ($spare[$s['key']] ?? [] as $num => $d) {
      if ($num === (int)$s['need']['number'] || $d['left'] <= 0) continue;
      if ($best === null || $d['left'] > $spare[$s['key']][$best]['left']) $best = $num;
    }
    if ($best === null) break;
    $give = min($left, $spare[$s['key']][$best]['left']);
    $spare[$s['key']][$best]['left'] -= $give;
    $sources[] = ['label' => $spare[$s['key']][$best]['b']['label'], 'qty' => $give];
    $left -= $give;
  }
  $advisories[] = ['need' => $s['need'], 'item' => $s['item'], 'needed' => $needed, 'sources' => $sources];
}

// Restocking needs per item, counted from the advisories above (feeds the Restocking Summary popup).
$restock_needs = [];
foreach ($advisories as $advisory) {
  $name = $advisory['item']['name'];
  if (!isset($restock_needs[$name])) $restock_needs[$name] = ['barangays' => [], 'needed' => 0];
  $restock_needs[$name]['barangays'][] = $advisory['need']['label'];
  $restock_needs[$name]['needed'] += $advisory['needed'];
}
uasort($restock_needs, function ($a, $b) { return $b['needed'] <=> $a['needed'] ?: count($b['barangays']) <=> count($a['barangays']); });
$units_needed = array_sum(array_column($restock_needs, 'needed'));

// Group the advisory by barangay so each barangay in need appears once.
$adv_groups = [];
foreach ($advisories as $advisory) {
  $n = (int)$advisory['need']['number'];
  if (!isset($adv_groups[$n])) $adv_groups[$n] = ['need' => $advisory['need'], 'rows' => []];
  $adv_groups[$n]['rows'][] = $advisory;
}
uasort($adv_groups, function ($a, $b) { return count($b['rows']) <=> count($a['rows']) ?: (int)$a['need']['number'] <=> (int)$b['need']['number']; });
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Analytics &amp; Forecast</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .an-wrap { max-width:1200px; margin:0 auto; }
    .an-kpis { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    .an-kpi { background:#fff; border-radius:12px; border-left:5px solid var(--c); padding:14px 18px; box-shadow:0 2px 8px rgba(16,24,40,.08); }
    .an-kpi span { display:block; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#667085; }
    .an-kpi b { display:block; font-size:28px; line-height:1.25; color:var(--c); }
    .an-kpi small { display:block; font-size:12px; color:#667085; }
    .an-card { background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 8px rgba(16,24,40,.08); }
    .an-head { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:14px; }
    .an-head h3 { margin:0; padding:0; background:none; font-size:17px; color:#1f2937; }
    .an-head p { margin:4px 0 0; font-size:12.5px; color:#667085; }
    .an-btn { display:inline-flex; align-items:center; gap:8px; flex-shrink:0; padding:9px 14px; border:0; border-radius:8px; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font:inherit; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
    .an-btn:hover, .an-btn:focus { filter:brightness(1.08); outline:none; }
    .an-btn .badge { background:rgba(255,255,255,.25); border-radius:999px; padding:1px 8px; font-size:11px; }
    .fc-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; padding:20px; background:rgba(16,24,40,.55); }
    .fc-modal.open { display:flex; }
    .fc-box { width:min(720px,100%); max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .fc-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; }
    .fc-head h3 { margin:0; padding:0; background:none; font-size:17px; color:#fff; }
    .fc-head p { margin:2px 0 0; font-size:12px; opacity:.9; }
    .fc-close { border:0; background:transparent; color:#fff; font-size:28px; line-height:1; cursor:pointer; padding:0 4px; border-radius:4px; }
    .fc-close:hover, .fc-close:focus { background:rgba(255,255,255,.2); outline:none; }
    .fc-body { padding:20px; overflow-y:auto; }
    .fc-stats { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin:0 0 18px; }
    .fc-stat { padding:12px; border-radius:8px; background:#fff7ed; border:1px solid #fde3c4; }
    .fc-stat b { display:block; font-size:22px; color:#9a4a00; }
    .fc-stat span { font-size:12px; color:#667085; }
    .fc-barangays { color:#667085; font-size:12px; }
    .fc-body .an-table th, .fc-body .an-table td { padding:8px 10px; }
    .fc-body .an-table th:not(:first-child), .fc-body .an-table td:not(:first-child) { width:auto; text-align:center; }
    .fc-link { display:block; margin-top:14px; font-size:12.5px; color:#667085; }
    .fc-link a { color:#c2410c; font-weight:700; }
    .an-none { color:#98a2b3; font-size:12.5px; }
    .an-group { border:1px solid #eaecf0; border-radius:10px; margin-bottom:10px; overflow:hidden; }
    .an-group summary { list-style:none; display:flex; align-items:center; gap:12px; padding:12px 16px; cursor:pointer; background:#fafbfc; }
    .an-group summary::-webkit-details-marker { display:none; }
    .an-group summary::after { content:'\25BE'; margin-left:auto; color:#98a2b3; transition:transform .2s; }
    .an-group[open] summary::after { transform:rotate(180deg); }
    .an-group summary strong { font-size:14px; color:#1f2937; }
    .an-group summary .an-dist { font-size:12.5px; color:#667085; }
    .an-table { width:100%; border-collapse:collapse; font-size:13px; }
    .an-table th { text-align:left; padding:8px 16px; color:#667085; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; border-top:1px solid #eaecf0; }
    .an-table td { padding:8px 16px; border-top:1px solid #f2f4f7; color:#344054; }
    .an-table th:nth-child(2), .an-table td:nth-child(2), .an-table th:nth-child(3), .an-table td:nth-child(3) { width:90px; text-align:center; }
    .an-src .pill { margin:2px 4px 2px 0; }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11.5px; font-weight:700; }
    .pill.red { background:#fee2e2; color:#991b1b; }
    .pill.green { background:#d1fae5; color:#065f46; }
    .pill.gray { background:#f2f4f7; color:#475467; }
    .pill.orange { background:#ffedd5; color:#9a3412; }
    .an-ok { color:#087443; background:#eef8f2; padding:14px; border-radius:8px; margin:0; }
    .analytics-page-shell { flex:1; min-height:0; height:100vh; }
    .analytics-scroll { min-height:0; overflow-y:auto; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    @media (max-width:1000px) { .an-kpis { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media (max-width:640px) { .an-kpis, .fc-stats { grid-template-columns:1fr; } .an-head { flex-direction:column; align-items:flex-start; } }
    @media (max-width:1200px) {
      .analytics-page-shell { height:auto; min-height:0; }
      .analytics-scroll { overflow-y:visible; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="analytics-page-shell" style="display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;"><h2 style="margin:0;font-size:24px;">Analytics &amp; Forecast</h2><p style="margin:4px 0 0;color:#666;font-size:13px;">City-wide stock status and suggested supply transfers between South Caloocan barangays.</p></header>
    <main class="main-content analytics-scroll" style="flex:1; padding:24px;">
      <div class="an-wrap">
        <div class="an-kpis">
          <div class="an-kpi" style="--c:#1e5fb9"><span>Reporting coverage</span><b><?php echo $coverage; ?>%</b><small><?php echo count($reported); ?> of <?php echo $total_barangays; ?> barangays reported</small></div>
          <div class="an-kpi" style="--c:#dc2626"><span>Critical</span><b><?php echo count($critical); ?></b><small>barangays with a medical supply at 5 or fewer</small></div>
          <div class="an-kpi" style="--c:#d97706"><span>Low stock</span><b><?php echo count($warning); ?></b><small>barangays with a medical supply at 6 to 15</small></div>
          <div class="an-kpi" style="--c:#059669"><span>Sufficient</span><b><?php echo count($optimal); ?></b><small>barangays with every supply above 15</small></div>
        </div>

        <div class="an-card">
          <div class="an-head">
            <div>
              <h3>Supply Redistribution Advisory</h3>
              <p>Each critical item needs enough to reach 16. Barangays with more than 16 share only their extra, and each unit is promised once. Click a barangay to see its items.</p>
            </div>
            <button type="button" class="an-btn" id="forecastOpen" aria-haspopup="dialog">📦 Restocking Summary<?php if ($advisories): ?> <span class="badge"><?php echo count($advisories); ?> short</span><?php endif; ?></button>
          </div>
          <?php if (!$adv_groups): ?>
            <p class="an-ok">No critical shortages among reporting barangays.</p>
          <?php else: $first = true; ?>
            <?php foreach ($adv_groups as $g): ?>
              <details class="an-group"<?php echo $first ? ' open' : ''; $first = false; ?>>
                <summary>
                  <strong><?php echo e($g['need']['label']); ?></strong>
                  <span class="an-dist"><?php echo e($g['need']['district']); ?></span>
                  <span class="pill red"><?php echo count($g['rows']); ?> item<?php echo count($g['rows']) === 1 ? '' : 's'; ?> short</span>
                </summary>
                <table class="an-table">
                  <thead><tr><th>Item</th><th>On hand</th><th>Needed</th><th>Suggested source</th></tr></thead>
                  <tbody>
                    <?php foreach ($g['rows'] as $advisory): ?>
                      <tr>
                        <td><?php echo e($advisory['item']['name']); ?></td>
                        <td><span class="pill red"><?php echo (int)$advisory['item']['quantity']; ?></span></td>
                        <td><?php echo (int)$advisory['needed']; ?></td>
                        <td class="an-src">
                          <?php foreach ($advisory['sources'] as $src): ?>
                            <span class="pill green"><?php echo e($src['label']); ?> &middot; <?php echo (int)$src['qty']; ?></span>
                          <?php endforeach; ?>
                          <?php if (!$advisory['sources']): ?>
                            <span class="an-none">No barangay has extra to share</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </details>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<div class="fc-modal" id="forecastModal" role="dialog" aria-modal="true" aria-labelledby="forecastTitle">
  <div class="fc-box">
    <div class="fc-head">
      <div>
        <h3 id="forecastTitle">📦 Restocking Summary</h3>
        <p>Which items are short, in which barangays, and how many units they need.</p>
      </div>
      <button type="button" class="fc-close" id="forecastClose" aria-label="Close">&times;</button>
    </div>
    <div class="fc-body">
      <div class="fc-stats">
        <div class="fc-stat"><b><?php echo count($advisories); ?></b><span>Critical shortages</span></div>
        <div class="fc-stat"><b><?php echo $units_needed; ?></b><span>Units needed in all</span></div>
      </div>
      <?php if (!$restock_needs): ?>
        <p class="an-ok">No critical shortages &ndash; nothing to restock right now.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="an-table">
            <thead><tr><th>Item</th><th>Barangays short</th><th>Units needed</th></tr></thead>
            <tbody>
              <?php foreach ($restock_needs as $name => $need): ?>
                <tr>
                  <td><strong><?php echo e($name); ?></strong><div class="fc-barangays"><?php $bl = array_unique($need['barangays']); natsort($bl); echo e(implode(', ', $bl)); ?></div></td>
                  <td><?php echo count($need['barangays']); ?></td>
                  <td><strong><?php echo (int)$need['needed']; ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <span class="fc-link">For 30-day demand and days until stock runs out, open the <a href="forecast.php">Demand Forecast</a> page.</span>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('forecastModal');
  var openBtn = document.getElementById('forecastOpen');
  var closeBtn = document.getElementById('forecastClose');
  if (!modal || !openBtn) return;
  function open() { modal.classList.add('open'); closeBtn.focus(); }
  function close() { modal.classList.remove('open'); openBtn.focus(); }
  openBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('open')) close();
  });
})();
</script>
</body>
</html>
