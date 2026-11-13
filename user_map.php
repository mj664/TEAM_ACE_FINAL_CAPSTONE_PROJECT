<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/south_caloocan_map.php';
require_login();
if (is_role_in(['head_admin', 'official', 'admin'])) {
    header('Location: admin_dashboard.php');
    exit;
}

$barangay_map_status = build_barangay_stock_status($conn);
$map_embed = ($_GET['embed'] ?? '') === '1';
$submitted_count = count(array_filter($barangay_map_status, static function ($barangay) {
    return !empty($barangay['has_report']);
}));
$total_barangays = count($barangay_map_status);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Barangay Map - DRRMC Caloocan Hub</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?php echo e(asset_v('assets/style.css')); ?>">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .user-map-page { padding: 24px; }
    .user-map-card { background:#fff; border:1px solid #e0e7e3; border-radius:10px; padding:20px; box-shadow:0 4px 14px rgba(30,77,55,.08); }
    .user-map-card h2 { margin:0; color:var(--primary); }
    .user-map-summary { display:flex; flex-wrap:wrap; gap:10px; margin:12px 0 18px; }
    .user-map-summary span { padding:8px 12px; border-radius:5px; background:#eef8f2; color:#1e4d37; font-size:13px; font-weight:700; }
    .user-map-summary .submitted { background:#dff4ea; color:#087443; }
    .user-map-legend { display:flex; flex-wrap:wrap; gap:14px; margin:12px 0; color:#59635e; font-size:13px; }
    .user-map-legend strong { color:#059669; }
    .user-map-frame { height:clamp(388px, calc(100vh - 272px), 578px); overflow:hidden; border:1px solid #dce5df; border-radius:8px; }
    .user-map-frame #southCaloocanMap { height:auto; }
    .user-map-frame .sc-map-shell, .user-map-frame .sc-map-pane, .user-map-frame .sc-map-frame { min-height:0; }
    .user-map-frame .sc-map-frame { display:flex; flex-direction:column; overflow:hidden; }
    .user-map-frame #map { flex:0 0 auto; height:clamp(360px, calc(100vh - 300px), 550px) !important; min-height:0; width:100%; }
    @media (max-width:760px) { .user-map-page { padding:12px; } .user-map-frame { height:clamp(388px, calc(100vh - 272px), 578px); } .user-map-frame #map { height:clamp(360px, 58vh, 550px) !important; } }
    .map-embed-body { overflow:hidden; }
    .map-embed-body .sidebar, .map-embed-body > .layout > div > header { display:none; }
    .map-embed-body > .layout { display:block; min-height:100vh; }
    .map-embed-body > .layout > div { height:100vh; }
    /* display:block: under 1200px style.css turns .main-content into a wrapping flex row, which shrank the embedded map to a narrow box. */
    .map-embed-body .main-content { display:block !important; width:100%; height:100vh; padding:0 !important; overflow:hidden !important; }
    .map-embed-body .user-map-page, .map-embed-body .user-map-card, .map-embed-body .user-map-frame,
    .map-embed-body #southCaloocanMap, .map-embed-body .sc-map-shell, .map-embed-body .sc-map-pane, .map-embed-body .sc-map-frame { width:100%; }
    .map-embed-body .sc-map-frame { border:0; border-radius:0; }
    .map-embed-body .user-map-page { height:100vh; padding:0; }
    .map-embed-body .user-map-card { height:100vh; padding:0; border:0; border-radius:0; box-shadow:none; }
    .map-embed-body .user-map-card > h2, .map-embed-body .user-map-summary, .map-embed-body .user-map-legend { display:none; }
    .map-embed-body .user-map-frame { height:100vh; border:0; border-radius:0; }
    .map-embed-body .user-map-frame #map { height:calc(100vh - 25px) !important; }
  </style>
</head>
<body class="<?= $map_embed ? 'map-embed-body' : '' ?>">
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;">South Caloocan Barangay Map</h2>
      <p style="margin:4px 0 0;color:#666;font-size:13px;">View barangay locations and check which areas have submitted equipment reports.</p>
    </header>
    <main class="main-content" style="flex:1;overflow-y:auto;">
      <div class="user-map-page">
        <section class="user-map-card">
          <h2>Barangay Report Map</h2>
          <div class="user-map-summary">
            <span><?php echo $total_barangays; ?> barangays shown</span>
            <span class="submitted">✓ <?php echo $submitted_count; ?> submitted reports</span>
          </div>
          <div class="user-map-legend"><span><strong>✓ Green check</strong> Report submitted</span><span>Gray marker: No report yet</span><span>Click a barangay marker for details</span></div>
          <div class="user-map-frame">
            <div id="southCaloocanMap" role="application" aria-label="South Caloocan barangay report map">
              <div class="sc-map-shell">
                <div class="sc-map-pane" id="scMapPane">
                  <div class="sc-map-frame">
                    <div class="sc-map-banner">SOUTH CALOOCAN BARANGAY REPORT MAP</div>
                    <div id="map"></div>
                  </div>
                </div>
                <div class="sc-list-pane" id="scListPane" hidden></div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
</div>
<script>
window.SOUTH_CALOOCAN_MAP = <?php echo json_encode([
  'lowStockThreshold' => 5,
  'center' => [14.6507, 120.9830],
  'zoom' => 14,
  'districts' => south_caloocan_districts_payload(),
  'geojson' => asset_v('assets/caloocan_barangays.json'),
  'barangays' => array_values($barangay_map_status),
], JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo e(asset_v('assets/south_caloocan_map.js')); ?>"></script>
</body>
</html>
