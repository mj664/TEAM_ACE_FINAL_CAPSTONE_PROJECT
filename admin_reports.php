<?php
require_once 'init.php';
require_login();
require_role(['head_admin','official','admin']);

$reports = $conn->query("SELECT er.*, u.fullname, u.email, u.barangay FROM equipment_reports er JOIN users u ON er.user_id=u.id ORDER BY er.submitted_at DESC");
$rows = [];
if ($reports) { while ($r = $reports->fetch_assoc()) $rows[] = $r; }

// Group reports by month (newest first). Totals use each barangay's latest report in the month
// so a barangay that submitted twice isn't counted twice.
$months = [];
foreach ($rows as $r) {
  $ym = date('Y-m', strtotime($r['submitted_at']));
  if (!isset($months[$ym])) $months[$ym] = ['label' => date('F Y', strtotime($r['submitted_at'])), 'reports' => [], 'latest' => []];
  $months[$ym]['reports'][] = $r;
  $bkey = strtolower(trim((string)$r['barangay'])) ?: ('user-' . $r['user_id']);
  if (!isset($months[$ym]['latest'][$bkey])) $months[$ym]['latest'][$bkey] = $r;
}
$can_monthly = is_role_in(['head_admin', 'official']);

// The main list only shows this month's reports. When the month ends the list starts fresh,
// and the finished month stays available under "Monthly Report".
$current_ym = date('Y-m');
$current_month_label = date('F Y');
$current_rows = array_values(array_filter($rows, function ($r) use ($current_ym) {
  return date('Y-m', strtotime($r['submitted_at'])) === $current_ym;
}));
// One card per barangay: its latest report up front, earlier ones this month folded underneath.
$current_groups = [];
foreach ($current_rows as $r) {
  $num = (int)preg_replace('/\D+/', '', (string)$r['barangay']);
  $gkey = $num ? 'b' . $num : 'u' . $r['user_id'];
  if (!isset($current_groups[$gkey])) $current_groups[$gkey] = ['label' => $num ? barangay_label($num) : ($r['barangay'] ?: 'No barangay'), 'reports' => [], 'search' => ''];
  $current_groups[$gkey]['reports'][] = $r;
  $current_groups[$gkey]['search'] .= ' ' . strtolower($r['fullname'] . ' ' . $r['email'] . ' ' . $r['barangay'] . ' ' . $current_groups[$gkey]['label']);
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Equipment Reports</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .report-list {
      display:flex;
      flex-direction:column;
      gap:10px;
      max-height:calc(100vh - 310px);
      overflow-y:auto;
      padding:2px 10px 2px 2px;
      scrollbar-color:#9aa7b5 #eef1f4;
      scrollbar-width:thin;
    }
    .report-list::-webkit-scrollbar { width:10px; }
    .report-list::-webkit-scrollbar-track { background:#eef1f4; border-radius:5px; }
    .report-list::-webkit-scrollbar-thumb { background:#9aa7b5; border-radius:5px; border:2px solid #eef1f4; }
    .report-list::-webkit-scrollbar-thumb:hover { background:#687585; }
    .report-search-bar { display:flex; align-items:center; gap:10px; margin:0 0 14px; padding:10px 14px; background:rgba(255,255,255,.94); border:1px solid rgba(255,255,255,.7); border-radius:12px; box-shadow:0 4px 14px rgba(120,60,0,.1); }
    .report-search-bar .icon { font-size:16px; line-height:1; }
    .report-search-input { flex:1; min-width:0; margin:0; border:1px solid #d0d5dd; border-radius:8px; padding:9px 12px; font-size:14px; background:#fff; }
    .report-search-input:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .report-search-count { color:#667085; font-size:12px; white-space:nowrap; }
    .month-picker { position:relative; }
    .month-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 14px; border:0; border-radius:8px; background:var(--theme-strong, #2d7a5c); color:#fff; font:inherit; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; box-shadow:0 4px 12px rgba(234,122,0,.25); }
    .month-btn:hover, .month-btn:focus { filter:brightness(1.08); outline:none; }
    .month-menu { display:none; position:absolute; right:0; top:calc(100% + 8px); z-index:950; width:240px; max-height:320px; overflow-y:auto; padding:6px; background:#fff; border:1px solid #f0e6da; border-radius:10px; box-shadow:0 12px 28px rgba(16,24,40,.18); }
    .month-menu.active { display:block; }
    .month-menu button { display:flex; justify-content:space-between; align-items:center; width:100%; padding:9px 10px; border:0; border-radius:6px; background:transparent; color:#1f2937; font:inherit; font-size:13px; text-align:left; cursor:pointer; }
    .month-menu button:hover, .month-menu button:focus { background:#fff7ed; color:#9a4a00; outline:none; }
    .month-menu .count { font-size:11px; color:#667085; background:#f2f4f7; border-radius:999px; padding:2px 8px; }
    .month-summary { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
    .month-stat { padding:12px; border-radius:8px; background:#fff7ed; border:1px solid #fde3c4; }
    .month-stat b { display:block; font-size:20px; color:#9a4a00; }
    .month-stat span { font-size:12px; color:#667085; }
    .month-section-title { margin:18px 0 8px; font-size:14px; color:#1f2937; }
    .month-report-list { display:flex; flex-direction:column; gap:6px; }
    .month-reports-toggle { margin:18px 0 0; border:1px solid #fde3c4; border-radius:10px; background:#fffaf4; overflow:hidden; }
    .month-reports-btn { display:flex; align-items:center; gap:10px; width:100%; margin:0; padding:12px 14px; border:0; background:transparent; font:inherit; font-size:14px; font-weight:700; color:#1f2937; text-align:left; cursor:pointer; }
    .month-reports-btn:hover, .month-reports-btn:focus-visible { background:#fff1df; outline:none; }
    .month-reports-caret { color:#c56a00; display:inline-block; transition:transform .15s; }
    .month-reports-btn[aria-expanded="true"] .month-reports-caret { transform:rotate(90deg); }
    .month-reports-label { flex:1; }
    .month-reports-label small { color:#667085; font-weight:400; }
    .month-toggle-hint { font-size:12px; font-weight:700; color:#fff; background:#ea7a00; border-radius:999px; padding:4px 12px; }
    .month-reports-toggle .month-report-list { padding:0 10px 10px; }
    .month-reports-toggle .month-report-list[hidden] { display:none; }
    .month-totals-title { margin-top:12px; }
    .month-reports-toggle + .month-reports-toggle { margin-top:10px; }
    .month-totals-body { display:block !important; padding:0 12px 12px !important; }
    .month-totals-body[hidden] { display:none !important; }
    .month-totals-body .month-section-title { margin-top:14px; }
    .month-report-list .report-item { padding:10px 12px; font-size:13px; }
    .month-totals { width:100%; border-collapse:collapse; font-size:12px; }
    .month-totals th, .month-totals td { padding:7px 8px; border-bottom:1px solid #f0f0f0; text-align:left; }
    .month-totals th { background:#f5f5f5; font-weight:600; }
    .month-totals td.num, .month-totals th.num { text-align:right; }
    #reportModal { z-index:1010; }
    .others-tag { display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; background:#fff7ed; border:1px solid #f5c48a; color:#9a4a00; font-size:11px; font-weight:700; vertical-align:middle; }
    .report-others { margin-top:16px; padding:12px; border:1px dashed #f5c48a; border-radius:8px; background:#fffaf4; }
    .report-others h4 { margin:0 0 8px; color:#9a4a00; }
    .report-others h4 small { color:#667085; font-weight:400; }
    .report-others-none { margin:0; color:#98a2b3; font-size:12px; }
    .report-empty-search { display:none; color:#777; padding:18px 0; }
    .layout .report-month-title { margin:0 0 10px; padding:0; background:none; color:#fff; font-size:15px; font-weight:700; text-shadow:0 1px 2px rgba(0,0,0,.25); }
    .report-month-empty { padding:22px; text-align:center; color:#667085; background:rgba(255,255,255,.94); border-radius:10px; }
    .report-item {
      width:100%; text-align:left; cursor:pointer;
      background:#fff; color:#263342; padding:14px 16px;
      border:1px solid #d5dce3; border-left:4px solid #526273; border-radius:5px; font:inherit;
      display:flex; justify-content:space-between; align-items:center; gap:12px;
      box-shadow:0 1px 2px rgba(16,24,40,0.08);
    }
    .report-item:hover, .report-item:focus { background:#f3f6f8; border-left-color:#2d7a5c; outline:none; }
    .report-item .hint { color:#526273; font-size:12px; font-weight:600; white-space:nowrap; }
    .report-item:hover .hint, .report-item:focus .hint { color:#1e4d37; }
    .brgy-group { background:#fff; border:1px solid #e4e7ec; border-left:4px solid #ea7a00; border-radius:10px; box-shadow:0 1px 3px rgba(16,24,40,.08); overflow:hidden; flex-shrink:0; }
    .brgy-group .brgy-latest { border:0; border-radius:0; box-shadow:none; padding:12px 16px; background:transparent; }
    .brgy-group .brgy-latest:hover, .brgy-group .brgy-latest:focus { background:#fff7ed; }
    .bg-main { display:flex; flex-direction:column; gap:2px; min-width:0; }
    .bg-name { font-size:15px; font-weight:700; color:#1f2937; }
    .bg-meta { font-size:12.5px; color:#667085; }
    .bg-right { display:flex; align-items:center; gap:12px; flex-shrink:0; }
    .bg-count { font-size:11.5px; font-weight:700; color:#9a4a00; background:#fff1df; border-radius:999px; padding:3px 10px; }
    .bg-older { border-top:1px solid #f2f4f7; }
    .bg-older summary { padding:8px 16px; font-size:12.5px; font-weight:600; color:#c56a00; cursor:pointer; }
    .bg-older-list { display:flex; flex-direction:column; gap:4px; padding:0 12px 10px; }
    .bg-older-list .report-item { padding:8px 12px; font-size:13px; border-left-width:3px; box-shadow:none; }
    .report-row { display:grid; grid-template-columns: 1fr 60px 50px 50px minmax(120px,1.4fr); gap:8px; margin-bottom:6px; font-size:12px; align-items:center; }
    .report-row.header { font-weight:600; background:#f5f5f5; padding:8px; border-radius:4px; }
    .report-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1000; align-items:center; justify-content:center; padding:20px; }
    .report-modal.active { display:flex; }
    .report-modal-box { background:#fff; width:min(920px,100%); max-height:90vh; border-radius:10px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 12px 40px rgba(0,0,0,0.25); }
    .report-modal-head { background:#2d7a5c; color:#fff; padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .report-modal-head h3 { margin:0; font-size:16px; font-weight:600; }
    .report-modal-close { background:transparent; border:0; color:#fff; font-size:26px; line-height:1; cursor:pointer; padding:0 4px; border-radius:4px; }
    .report-modal-close:hover, .report-modal-close:focus { background:rgba(255,255,255,0.18); outline:none; }
    .report-modal-body { padding:16px; overflow-y:auto; }
    @media (max-width:700px) {
      .report-list { max-height:calc(100vh - 285px); padding-right:6px; }
      .report-search-count { display:none; }
      .report-search-bar { flex-wrap:wrap; }
      .month-picker, .month-btn { width:100%; justify-content:center; }
      .report-item { align-items:flex-start; flex-direction:column; gap:4px; }
    }
  .print-pick{display:inline-flex;align-items:center;gap:8px;margin:0 10px 0 0;font-size:13px;font-weight:700;color:#344054;}.print-pick select{width:auto;margin:0;padding:8px 10px;border:1px solid #f5c48a;border-radius:8px;font:inherit;font-size:13px;background:#fff;}
</style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div style="flex:1;display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h2 style="margin:0;font-size:24px;">Equipment & Supply Reports</h2>
        <p style="margin:4px 0 0 0;color:#666;font-size:13px;">Submitted by personnel from all barangays</p>
      </div>
    </header>

    <main class="main-content" style="flex:1;overflow-y:auto;">
      <div class="container">
        <?php
          if (!empty($_GET['msg'])){
            $m = $_GET['msg'];
            if ($m === 'approved') {
              echo '<div class="notice" style="margin:12px 0;">User approved successfully.';
              if (!empty($_GET['mail'])) echo ' Email: ' . e($_GET['mail']) . '.';
              echo '</div>';
            } elseif ($m === 'rejected') {
              echo '<div class="errors" style="margin:12px 0;">User rejected.';
              if (!empty($_GET['mail'])) echo ' Email: ' . e($_GET['mail']) . '.';
              echo '</div>';
            }
          }
        ?>

        <?php
          $fields = ['portable_oxygen', 'stretcher_scoop', 'spider_strap', 'bp_automatic', 'pulse_oximeter', 'facemask',
                     'elastic_bandage', 'gloves', 'betadine_dropper', 'surgical_tape', 'gauze_pad', 'nasal_cannula',
                     'oxygen_mask', 't_bandage', 's_splint', 'w_splint', 'umbrella', 'cold_hot_compress',
                     'cervical_collar', 'first_aid_kit', 'stretcher', 'backboard', 'resuscitator', 'defibrillator',
                     'aspirator', 'flashlight', 'rope', 'carabiner', 'harness', 'life_jacket', 'helmet',
                     'impact_resistant_boots', 'hazmat_suit'];
          $labels = [
            'portable_oxygen' => 'Portable Oxygen',
            'stretcher_scoop' => 'Stretcher / Scoop',
            'spider_strap' => 'Spider Strap',
            'bp_automatic' => 'BP Automatic / Manual',
            'pulse_oximeter' => 'Pulse Oximeter',
            'facemask' => 'Facemask',
            'elastic_bandage' => 'Elastic Bandage',
            'gloves' => 'Gloves',
            'betadine_dropper' => 'Betadine Dropper',
            'surgical_tape' => 'Surgical Tape',
            'gauze_pad' => '4x4 Gauze Pad',
            'nasal_cannula' => 'Nasal Cannula',
            'oxygen_mask' => 'Oxygen Mask',
            't_bandage' => 'T - Bandage',
            's_splint' => 'S Splint',
            'w_splint' => 'W- Splint',
            'umbrella' => 'Umbrella',
            'cold_hot_compress' => 'Cold / Hot Compress',
            'cervical_collar' => 'Cervical Collar',
            'first_aid_kit' => 'First Aid Kit',
            'stretcher' => 'Stretcher',
            'backboard' => 'Backboard',
            'resuscitator' => 'Resuscitator',
            'defibrillator' => 'Defibrillator (AED)',
            'aspirator' => 'Aspirator/Suction',
            'flashlight' => 'Flashlight',
            'rope' => 'Rope / Cord',
            'carabiner' => 'Carabiner',
            'harness' => 'Safety Harness',
            'life_jacket' => 'Life Jacket',
            'helmet' => 'Safety Helmet',
            'impact_resistant_boots' => 'Impact Resistant Boots',
            'hazmat_suit' => 'HAZMAT Suit'
          ];
        ?>

        <?php if ($rows): ?>
          <div class="report-search-bar" role="search">
            <span class="icon" aria-hidden="true">&#128269;</span>
            <input type="search" class="report-search-input" id="reportSearch" placeholder="Search by name, email, or barangay" aria-label="Search reports" autocomplete="off">
            <span class="report-search-count" id="reportSearchCount"><?php echo count($current_groups); ?> barangay<?php echo count($current_groups) === 1 ? '' : 's'; ?> &middot; <?php echo count($current_rows); ?> report<?php echo count($current_rows) === 1 ? '' : 's'; ?> in <?php echo e(date('F')); ?></span>
            <?php if ($can_monthly): ?>
            <div class="month-picker">
              <button type="button" class="month-btn" id="monthBtn" aria-haspopup="true" aria-expanded="false">&#128197; Monthly Report &#9662;</button>
              <div class="month-menu" id="monthMenu" role="menu">
                <?php foreach ($months as $ym => $m): ?>
                  <button type="button" role="menuitem" data-month="<?php echo e($ym); ?>">
                    <span><?php echo e($m['label']); ?><?php if ($ym === $current_ym): ?> <small style="color:#3f9b46;font-weight:700;">&middot; current</small><?php endif; ?></span>
                    <span class="count"><?php echo count($m['reports']); ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <h3 class="report-month-title"><?php echo e($current_month_label); ?> reports</h3>
          <div class="report-list">
          <?php if (!$current_rows): ?>
            <div class="report-month-empty">No equipment reports submitted yet for <?php echo e($current_month_label); ?>.<?php if ($can_monthly): ?> Earlier months are under <strong>Monthly Report</strong>.<?php endif; ?></div>
          <?php endif; ?>
          <?php foreach ($current_groups as $grp): $latest = $grp['reports'][0]; $older = array_slice($grp['reports'], 1); $lo = report_other_items($latest); ?>
            <div class="brgy-group" data-search="<?php echo e(trim($grp['search'])); ?>">
              <button type="button" class="report-item brgy-latest" data-report="<?php echo (int)$latest['id']; ?>">
                <span class="bg-main">
                  <span class="bg-name"><?php echo e($grp['label']); ?><?php if ($lo): ?> <span class="others-tag">+<?php echo count($lo); ?> other item<?php echo count($lo) === 1 ? '' : 's'; ?></span><?php endif; ?></span>
                  <span class="bg-meta"><?php echo e($latest['fullname']); ?> &middot; latest report <?php echo date('M d, Y g:i A', strtotime($latest['submitted_at'])); ?></span>
                </span>
                <span class="bg-right"><span class="bg-count"><?php echo count($grp['reports']); ?> report<?php echo count($grp['reports']) === 1 ? '' : 's'; ?></span><span class="hint">View latest &rarr;</span></span>
              </button>
              <?php if ($older): ?>
                <details class="bg-older">
                  <summary>Earlier reports this month (<?php echo count($older); ?>)</summary>
                  <div class="bg-older-list">
                    <?php foreach ($older as $or): ?>
                      <button type="button" class="report-item" data-report="<?php echo (int)$or['id']; ?>"><span><?php echo date('M d, Y g:i A', strtotime($or['submitted_at'])); ?> &middot; <?php echo e($or['fullname']); ?></span><span class="hint">View &rarr;</span></button>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php foreach ($rows as $rep): ?>
            <?php
              $rid = (int)$rep['id'];
              $title = e($rep['fullname']) . ' (' . e($rep['barangay']) . ') - ' . date('M d, Y g:i A', strtotime($rep['submitted_at']));
              $search_text = e(strtolower($rep['fullname'] . ' ' . $rep['email'] . ' ' . $rep['barangay']));
              // Every report keeps its inventory template (the Monthly Report popup opens them), but only this month's are listed.
              $in_current_month = date('Y-m', strtotime($rep['submitted_at'])) === $current_ym;
              $rep_others = report_other_items($rep); // items the barangay added under "4. Others"
            ?>
            <?php if (false): // listed in the barangay cards above ?>
            <button type="button" class="report-item" data-report="<?php echo $rid; ?>" data-search="<?php echo $search_text; ?>">
              <span><strong><?php echo e($rep['fullname']); ?></strong> (<?php echo e($rep['barangay']); ?>) — <?php echo date('M d, Y g:i A', strtotime($rep['submitted_at'])); ?><?php if ($rep_others): ?> <span class="others-tag">+<?php echo count($rep_others); ?> other item<?php echo count($rep_others) === 1 ? '' : 's'; ?></span><?php endif; ?></span>
              <span class="hint">View inventory →</span>
            </button>
            <?php endif; ?>
            <template id="report-data-<?php echo $rid; ?>" data-title="<?php echo $title; ?>">
                <?php if (!is_role_in(['head_admin'])): // City Head only views reports ?><div style="display:flex;justify-content:flex-end;align-items:center;margin:0 0 12px;"><label class="print-pick">Print <select class="print-sections" title="Choose what part of the report to print"><option value="">Whole report</option><option value="equipment">Tools &amp; equipment only</option><option value="supplies">Medical supplies only</option><option value="equipment,supplies">Tools &amp; equipment + medical supplies</option><option value="others">Other items only</option></select></label><a href="report_print.php?id=<?php echo $rid; ?>&amp;autoprint=1" style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;background:linear-gradient(135deg,#ea7a00,#3f9b46);color:#fff;font-weight:700;font-size:13px;text-decoration:none;">🖨️ Print / Save as PDF</a></div><?php endif; ?>
                <div class="report-row header" style="margin-bottom:12px;">
                  <span>Equipment</span>
                  <span>Qty</span>
                  <span>IN</span>
                  <span>OUT</span>
                  <span>Remarks</span>
                </div>
                <?php
                  foreach ($fields as $f) {
                    $qty = isset($rep[$f]) ? $rep[$f] : 0;
                    $rem = $rep[$f.'_remarks'] ?? '';
                    echo '<div class="report-row">';
                    echo '<span>'.e($labels[$f] ?? $f).'</span>';
                    echo '<span>'.e($qty).'</span>';
                    echo '<span>—</span>';
                    echo '<span>—</span>';
                    echo '<span>'.(trim((string)$rem) !== '' ? e($rem) : '—').'</span>';
                    echo '</div>';
                  }
                ?>
                <div class="report-others">
                  <h4>Other items <small>(not on the standard checklist)</small></h4>
                  <?php if (!$rep_others): ?>
                    <p class="report-others-none">None reported.</p>
                  <?php else: ?>
                    <div class="report-row header"><span>Item</span><span>Qty</span><span></span><span></span><span>Remarks</span></div>
                    <?php foreach ($rep_others as $ro): ?>
                      <div class="report-row"><span><?php echo e($ro['name']); ?></span><span><?php echo (int)$ro['qty']; ?></span><span></span><span></span><span><?php echo $ro['remarks'] !== '' ? e($ro['remarks']) : '—'; ?></span></div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
            </template>
          <?php endforeach; ?>
          </div>
          <p class="report-empty-search" id="reportEmptySearch">No reports match your search.</p>

          <?php if ($can_monthly): ?>
          <?php foreach ($months as $ym => $m): ?>
            <?php
              $latest = $m['latest'];
              $totals = array_fill_keys($fields, 0); $other_totals = [];
              foreach ($latest as $lr) {
                foreach ($fields as $f) $totals[$f] += (int)($lr[$f] ?? 0);
                // "Others" items summed by name (case-insensitive) across the barangays that reported them.
                foreach (report_other_items($lr) as $ro) {
                  $okey = mb_strtolower($ro['name']);
                  if (!isset($other_totals[$okey])) $other_totals[$okey] = ['name' => $ro['name'], 'qty' => 0, 'barangays' => []];
                  $other_totals[$okey]['qty'] += $ro['qty'];
                  $other_totals[$okey]['barangays'][(string)$lr['barangay']] = true;
                }
              }
            ?>
            <template id="month-data-<?php echo e($ym); ?>" data-title="Monthly Report — <?php echo e($m['label']); ?>">
              <div class="month-summary">
                <div class="month-stat"><b><?php echo count($m['reports']); ?></b><span>Reports submitted</span></div>
                <div class="month-stat"><b><?php echo count($latest); ?></b><span>Barangays reporting</span></div>
              </div>

              <div class="month-reports-toggle">
                <button type="button" class="month-reports-btn" aria-expanded="false">
                  <span class="month-reports-caret" aria-hidden="true">&#9656;</span>
                  <span class="month-reports-label">Reports this month <small>(<?php echo count($m['reports']); ?>)</small></span>
                  <span class="month-toggle-hint" data-what="reports">Show reports</span>
                </button>
                <div class="month-report-list" hidden>
                  <?php foreach ($m['reports'] as $mr): ?>
                    <button type="button" class="report-item" data-report="<?php echo (int)$mr['id']; ?>">
                      <span><strong><?php echo e($mr['fullname']); ?></strong> (<?php echo e($mr['barangay']); ?>) — <?php echo date('M d, Y g:i A', strtotime($mr['submitted_at'])); ?></span>
                      <span class="hint">View inventory →</span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="month-reports-toggle">
                <button type="button" class="month-reports-btn" aria-expanded="false">
                  <span class="month-reports-caret" aria-hidden="true">&#9656;</span>
                  <span class="month-reports-label">Consolidated equipment totals <small>(latest report per barangay)</small></span>
                  <span class="month-toggle-hint" data-what="totals">Show totals</span>
                </button>
                <div class="month-report-list month-totals-body" hidden>
              <table class="month-totals">
                <thead><tr><th>Equipment</th><th class="num">Total Qty</th></tr></thead>
                <tbody>
                  <?php foreach ($fields as $f): ?>
                    <tr><td><?php echo e($labels[$f] ?? $f); ?></td><td class="num"><?php echo (int)$totals[$f]; ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php if ($other_totals): ?>
                <h4 class="month-section-title">Other items reported <small style="color:#667085;font-weight:400;">(added by barangays under "Others")</small></h4>
                <table class="month-totals">
                  <thead><tr><th>Item</th><th>Barangays</th><th class="num">Total Qty</th></tr></thead>
                  <tbody>
                    <?php foreach ($other_totals as $ot): ?>
                      <tr><td><?php echo e($ot['name']); ?></td><td><?php echo e(implode(', ', array_map('barangay_label', array_keys($ot['barangays'])))); ?></td><td class="num"><?php echo (int)$ot['qty']; ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
                </div>
              </div>
            </template>
          <?php endforeach; ?>

          <div id="monthModal" class="report-modal" role="dialog" aria-modal="true" aria-labelledby="monthModalTitle">
            <div class="report-modal-box">
              <div class="report-modal-head">
                <h3 id="monthModalTitle">Monthly Report</h3>
                <button type="button" class="report-modal-close" id="monthModalClose" aria-label="Close">×</button>
              </div>
              <div class="report-modal-body" id="monthModalBody"></div>
            </div>
          </div>
          <?php endif; ?>
          <div id="reportModal" class="report-modal" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle">
            <div class="report-modal-box">
              <div class="report-modal-head">
                <h3 id="reportModalTitle">Inventory Report</h3>
                <button type="button" class="report-modal-close" id="reportModalClose" aria-label="Close">×</button>
              </div>
              <div class="report-modal-body" id="reportModalBody"></div>
            </div>
          </div>
        <?php else: ?>
          <div class="card" style="text-align:center;padding:40px;">
            <p style="color:#999;">No equipment reports submitted yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('reportModal');
  if (!modal) return;
  var titleEl = document.getElementById('reportModalTitle');
  var bodyEl = document.getElementById('reportModalBody');
  var closeBtn = document.getElementById('reportModalClose');
  var searchInput = document.getElementById('reportSearch');
  var emptySearch = document.getElementById('reportEmptySearch');
  var searchCount = document.getElementById('reportSearchCount');

  function openReport(id) {
    var tpl = document.getElementById('report-data-' + id);
    if (!tpl) return;
    titleEl.textContent = tpl.getAttribute('data-title') || 'Inventory Report';
    bodyEl.innerHTML = tpl.innerHTML;
    modal.classList.add('active');
    closeBtn.focus();
  }

  function closeReport() {
    modal.classList.remove('active');
    bodyEl.innerHTML = '';
  }

  // Delegated so report rows inside the monthly popup open too.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.report-item');
    if (btn) openReport(btn.getAttribute('data-report'));
  });

  // Monthly popup: the "Reports this month" button shows or hides the list of reports.
  document.addEventListener('click', function (e) {
    var t = e.target.closest('.month-reports-btn');
    if (!t) return;
    var list = t.parentNode.querySelector('.month-report-list');
    var open = t.getAttribute('aria-expanded') !== 'true';
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (list) list.hidden = !open;
    var hint = t.querySelector('.month-toggle-hint');
    if (hint) hint.textContent = (open ? 'Hide ' : 'Show ') + (hint.dataset.what || 'reports');
  });

  var monthBtn = document.getElementById('monthBtn');
  var monthMenu = document.getElementById('monthMenu');
  var monthModal = document.getElementById('monthModal');
  var monthTitle = document.getElementById('monthModalTitle');
  var monthBody = document.getElementById('monthModalBody');
  var monthClose = document.getElementById('monthModalClose');

  function setMonthMenu(open) {
    monthMenu.classList.toggle('active', open);
    monthBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function openMonth(ym) {
    var tpl = document.getElementById('month-data-' + ym);
    if (!tpl) return;
    monthTitle.textContent = tpl.getAttribute('data-title') || 'Monthly Report';
    monthBody.innerHTML = tpl.innerHTML;
    monthModal.classList.add('active');
    monthClose.focus();
  }

  function closeMonth() {
    monthModal.classList.remove('active');
    monthBody.innerHTML = '';
  }

  if (monthBtn) {
    monthBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setMonthMenu(!monthMenu.classList.contains('active'));
    });
    monthMenu.addEventListener('click', function (e) {
      var item = e.target.closest('[data-month]');
      if (!item) return;
      setMonthMenu(false);
      openMonth(item.getAttribute('data-month'));
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.month-picker')) setMonthMenu(false);
    });
    monthClose.addEventListener('click', closeMonth);
    monthModal.addEventListener('click', function (e) {
      if (e.target === monthModal) closeMonth();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      var query = searchInput.value.trim().toLowerCase();
      var visibleCount = 0;
      var visibleReports = 0;
      document.querySelectorAll('.report-list .brgy-group').forEach(function (grp) {
        var matches = !query || (grp.getAttribute('data-search') || '').indexOf(query) !== -1;
        grp.style.display = matches ? '' : 'none';
        if (matches) { visibleCount++; visibleReports += grp.querySelectorAll('.report-item').length; }
      });
      if (emptySearch) emptySearch.style.display = (visibleCount || !query) ? 'none' : 'block';
      if (searchCount) searchCount.textContent = visibleCount + (visibleCount === 1 ? ' barangay' : ' barangays') + ' \u00b7 ' + visibleReports + (visibleReports === 1 ? ' report' : ' reports') + ' in <?php echo e(date('F')); ?>';
    });
  }

  closeBtn.addEventListener('click', closeReport);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeReport();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modal.classList.contains('active')) closeReport();
    else if (monthModal && monthModal.classList.contains('active')) closeMonth();
    else if (monthMenu) setMonthMenu(false);
  });
})();
</script>
<script>
// Print buttons: load the formatted report in a hidden frame and open the print dialog right here (no new tab).
document.addEventListener('click', function (e) {
  var a = e.target.closest('a[href*="report_print.php"]');
  if (!a) return;
  e.preventDefault();
  var old = document.getElementById('reportPrintFrame'); if (old) old.remove();
  var f = document.createElement('iframe');
  f.id = 'reportPrintFrame';
  f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  var pick = (a.closest('.report-modal-body, .report-history-content') || document).querySelector('.print-sections');
  f.src = a.getAttribute('href').replace(/&(amp;)?autoprint=1/, '') + '&inframe=1' + (pick && pick.value ? '&sections=' + encodeURIComponent(pick.value) : '');
  f.onload = function () { setTimeout(function () { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (err) { window.open(a.href, '_blank'); } }, 300); };
  document.body.appendChild(f);
}, true);
</script>
</body>
</html>
