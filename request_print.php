<?php
// Printable "Resource Request Form" in the same barangay DRRMC paper format as the equipment report (report_print.php).
// ?id=<request id>   DRRMC Admin: any request; barangay staff: their own barangay's requests.
require_once 'init.php';
require_login();
require_once __DIR__ . '/resource_request_lib.php';

// Same rule as report printing: the City Head only views; printing is for DRRMC officials and barangay staff.
if (is_role_in(['head_admin'])) { http_response_code(403); exit('Printing is not available for the City Head account.'); }
$is_office = is_role_in(['admin', 'official']);

$id = (int)($_GET['id'] ?? 0);
$st = $conn->prepare('SELECT rr.*, u.fullname, u.position, u.barangay AS brgy, u.address AS staff_address, u.profile_logo, rv.fullname AS reviewer, rl.fullname AS releaser
    FROM resource_requests rr INNER JOIN users u ON u.id = rr.user_id LEFT JOIN users rv ON rv.id = rr.reviewed_by LEFT JOIN users rl ON rl.id = rr.released_by WHERE rr.id = ? LIMIT 1');
$st->bind_param('i', $id);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();
if ($r && !$is_office) {
    // Barangay staff: only requests of their own barangay.
    $me = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
    $uid = (int)$_SESSION['user_id'];
    $me->bind_param('i', $uid); $me->execute();
    $mine = (string)($me->get_result()->fetch_assoc()['barangay'] ?? '');
    $me->close();
    if ((string)(int)$mine !== (string)(int)$r['brgy']) $r = null;
}
if (!$r) { http_response_code(404); exit('Request not found.'); }
audit_log('RESOURCE_REQUEST_PRINTED', 'request #' . $id, $r['request_type'] . ' - ' . barangay_label($r['brgy']));

// Stock the barangay had when it sent the request: its latest equipment report at that time.
$on_hand = [];
$oh = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id
    WHERE u.barangay = ? AND er.submitted_at <= ? ORDER BY er.submitted_at DESC, er.id DESC LIMIT 1");
$b = (string)$r['brgy'];
$oh->bind_param('ss', $b, $r['requested_at']);
$oh->execute();
$on_hand = $oh->get_result()->fetch_assoc() ?: [];
$oh->close();
$stock = function ($key) use ($on_hand) { $v = trim((string)($on_hand[$key] ?? '')); return ($v === '' || !is_numeric($v)) ? '' : max(0, (int)$v); };

$items = rr_parse_items($r['items_requested']);
$status = ['pending' => 'PENDING REVIEW', 'approved' => 'APPROVED', 'rejected' => 'DECLINED', 'deleted' => 'REMOVED'][$r['status']] ?? strtoupper($r['status']);
$logo = (string)($r['profile_logo'] ?? '');
?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Resource Request #<?= (int)$r['id'] ?></title>
<?php app_favicon(); ?>
<style>
  * { box-sizing:border-box; }
  body { margin:0; background:#e5e7eb; font-family:Arial, Helvetica, sans-serif; color:#000; }
  .toolbar { position:sticky; top:0; z-index:5; display:flex; gap:10px; justify-content:center; padding:12px; background:#1f2937; }
  .toolbar button { padding:10px 20px; border:0; border-radius:8px; font:inherit; font-size:14px; font-weight:700; cursor:pointer; }
  .toolbar .print { background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; }
  .toolbar .close { background:#fff; color:#1f2937; }
  .page { width:8.5in; min-height:11in; margin:18px auto; padding:0.55in 0.6in; background:#fff; box-shadow:0 4px 18px rgba(0,0,0,.18); }
  .head { display:grid; grid-template-columns:1.25in 1fr 1.25in; align-items:center; gap:10px; text-align:center; }
  .head img { width:1.15in; height:1.15in; object-fit:contain; border-radius:50%; }
  .head .blank { width:1.15in; height:1.15in; }
  .head p { margin:0; font-family:Calibri, Arial, sans-serif; font-weight:700; font-size:13.5px; line-height:1.4; }
  h1 { margin:30px 0 4px; text-align:center; font-size:21px; font-weight:800; letter-spacing:.2px; }
  .month { margin:0 0 4px; text-align:center; font-size:13px; font-weight:800; letter-spacing:.4px; }
  .sub { text-align:center; font-size:11.5px; margin:0 0 12px; }
  .meta { display:grid; grid-template-columns:1fr 1fr; border:1px solid #000; border-bottom:0; font-size:11.5px; }
  .meta div { padding:5px 7px; border-bottom:1px solid #000; }
  .meta div:nth-child(odd) { border-right:1px solid #000; }
  table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:11.5px; }
  th, td { border:1px solid #000; padding:5px 6px; text-align:center; vertical-align:middle; }
  th { font-weight:700; }
  td.name { text-align:left; text-transform:uppercase; }
  tr.grp th { padding:7px 6px; background:#f3f4f6; }
  tr.total td { font-weight:700; }
  .reason { margin-top:12px; border:1px solid #000; padding:7px 8px; font-size:11.5px; min-height:0.6in; }
  .reason b { display:block; margin-bottom:4px; }
  .sign { display:grid; grid-template-columns:1fr 1fr; gap:1.2in; margin-top:40px; font-size:12.5px; }
  .sign b { display:block; margin-bottom:46px; }
  .sign .line { border-top:1px solid #000; padding-top:3px; font-size:10.5px; font-weight:700; }
  .sign .who { text-align:center; font-size:12px; text-transform:uppercase; height:16px; }
  .sign .when { font-size:10px; margin-top:3px; }
  .sign { row-gap:0; } .sign > div:nth-child(n+3) { margin-top:30px; }
  @page { size:letter; margin:0; }
  @media print {
    body { background:#fff; } .toolbar { display:none; }
    .page { width:auto; min-height:0; margin:0; padding:0.4in 0.55in; box-shadow:none; }
    .head img, .head .blank { width:0.85in; height:0.85in; } .head { grid-template-columns:0.95in 1fr 0.95in; }
    .head p { font-size:11.5px; line-height:1.3; }
    .month { font-size:11px; margin-bottom:2px; }
    h1 { margin:12px 0 2px; font-size:17px; } .sub { font-size:10px; margin-bottom:6px; }
    table, .meta, .reason { font-size:9.5px; } th, td { padding:1.6px 5px; } tr.grp th { padding:3px 5px; }
    .meta div { padding:2px 5px; } .reason { margin-top:6px; padding:4px 6px; min-height:0; }
    .sign { margin-top:18px; font-size:11px; } .sign b { margin-bottom:26px; }
  }
</style>
</head><body>
<div class="toolbar"><button class="print" onclick="window.print()">🖨️ Print / Save as PDF</button><button class="close" onclick="window.close(); history.back();">Close</button></div>
<div class="page">
  <div class="head">
    <?php if ($logo !== '' && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt="Barangay logo"><?php else: ?><span class="blank"></span><?php endif; ?>
    <div>
      <p>Republic of the Philippines</p>
      <p>CITY OF CALOOCAN</p>
      <p><?= e(strtoupper(barangay_label($r['brgy']))) ?> DISASTER RISK REDUCTION AND MANAGEMENT COUNCIL</p>
      <?php if (trim((string)$r['staff_address']) !== ''): ?><p><?= e($r['staff_address']) ?></p><?php endif; ?>
    </div>
    <img src="assets/cdrrmd-logo.jpg" alt="CDRRMD logo">
  </div>
  <h1>RESOURCE REQUEST FORM</h1>
  <p class="month">REQUISITION FOR THE MONTH OF <?= e(strtoupper(date('F Y', strtotime($r['requested_at'])))) ?></p>
  <p class="sub">Request #<?= (int)$r['id'] ?></p>
  <div class="meta">
    <div><b>Request type:</b> <?= e($r['request_type']) ?></div>
    <div><b>Status:</b> <?= e($status) ?><?= !empty($r['reviewed_at']) && $r['status'] !== 'pending' ? ' (' . e(date('M j, Y', strtotime($r['reviewed_at']))) . ')' : '' ?></div>
    <div><b>Barangay:</b> <?= e(barangay_label($r['brgy'])) ?></div>
    <div><b>Requested by:</b> <?= e($r['fullname']) ?></div>
    <?php if (!empty($r['delivery_at'])): ?><div style="grid-column:1 / -1;border-right:0;"><b><?= e(rr_method_label($r)) ?>:</b> <?= e(rr_delivery_line($r)) ?> (<?= e(trim(preg_replace('/^\S+\s/u', '', rr_delivery_badge($r)[1]))) ?><?= !empty($r['received_at']) ? ' ' . e(date('M j, Y g:i A', strtotime($r['received_at']))) : (!empty($r['delivered_at']) ? ' ' . e(date('M j, Y g:i A', strtotime($r['delivered_at']))) : '') ?>)</div>
    <?php if (!empty($r['preferred_method'])): ?><div style="grid-column:1 / -1;border-right:0;"><b>Requested by the barangay:</b> <?= $r['preferred_method'] === 'pickup' ? 'Pick-up' : 'Delivery' ?><?= !empty($r['method_reason']) ? ' &rarr; changed to Pick-up. Reason: ' . e($r['method_reason']) : '' ?></div><?php endif; ?>
    <?php if (rr_is_pickup($r) && !empty($r['claimed_by_name'])): ?><div style="grid-column:1 / -1;border-right:0;"><b>Claimed by:</b> <?= e($r['claimed_by_name']) ?><?= !empty($r['claimed_rep']) ? ' (representative, authorization letter checked)' : ' (registered staff, staff ID checked)' ?> on <?= e(date('F j, Y g:i A', strtotime($r['delivered_at']))) ?></div><?php endif; ?>
    <?php if (rr_history_lines($r)): ?><div style="grid-column:1 / -1;border-right:0;"><b>Delivery / pick-up history:</b><?php foreach (rr_history_lines($r) as $hl): ?><br>&bull; <?= e($hl) ?><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
  </div>
  <table>
    <colgroup><col style="width:46%"><col style="width:16%"><col style="width:16%"><col style="width:22%"></colgroup>
    <?php foreach ($items['sections'] as $title => $rows): ?>
      <tr class="grp"><th style="text-align:left;"><?= e($title) ?></th><th>ON HAND</th><th>QTY REQUESTED</th><th>REMARKS</th></tr>
      <?php foreach ($rows as [$key, $label, $qty]): ?>
        <tr><td class="name"><?= e($label) ?></td><td><?= e((string)$stock($key)) ?></td><td><?= (int)$qty ?></td><td></td></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($items['other']): ?>
      <tr class="grp"><th style="text-align:left;">OTHER ITEMS</th><th></th><th>QTY REQUESTED</th><th>REMARKS</th></tr>
      <?php foreach ($items['other'] as [$name, $qty]): ?>
        <tr><td class="name"><?= e($name) ?></td><td></td><td><?= $qty === null ? '' : (int)$qty ?></td><td></td></tr>
      <?php endforeach; ?>
    <?php endif; ?>
    <tr class="total"><td class="name">TOTAL UNITS REQUESTED</td><td></td><td><?= (int)$items['total'] ?></td><td></td></tr>
  </table>
  <div class="reason"><b>REASON / PURPOSE OF REQUEST:</b><?= nl2br(e((string)$r['reason'])) ?></div>
  <?php if (trim((string)($r['admin_reply'] ?? '')) !== ''): ?><div class="reason"><b>DRRM OFFICE NOTE:</b><?= nl2br(e((string)$r['admin_reply'])) ?></div><?php endif; ?>
  <div class="sign">
    <div><b>REQUESTED BY:</b><div class="who"><?= e($r['fullname']) ?></div><div class="line">SIGNATURE OVER PRINTED NAME</div><div class="when">Barangay DRRM Staff<br>Date requested: <?= e(date('F j, Y', strtotime($r['requested_at']))) ?> &middot; <?= e(date('g:i A', strtotime($r['requested_at']))) ?></div></div>
    <div><b><?= $r['status'] === 'approved' ? 'APPROVED BY:' : 'REVIEWED BY:' ?></b><div class="who"><?= e((string)($r['reviewer'] ?? '')) ?></div><div class="line">SIGNATURE OVER PRINTED NAME</div><div class="when">DRRMC Admin<br><?= !empty($r['reviewed_at']) && $r['status'] !== 'pending' ? 'Date ' . ($r['status'] === 'approved' ? 'approved' : 'reviewed') . ': ' . e(date('F j, Y', strtotime($r['reviewed_at']))) . ' &middot; ' . e(date('g:i A', strtotime($r['reviewed_at']))) : 'Date approved: ____________________' ?></div></div>
    <?php if (rr_is_pickup($r)): // pick-up: the form doubles as the claim slip, signed at the City DRRM Office ?>
    <div><b>CLAIMED BY:</b><div class="who"><?= e((string)($r['claimed_by_name'] ?? '')) ?></div><div class="line">SIGNATURE OVER PRINTED NAME</div><div class="when"><?= !empty($r['claimed_rep']) ? 'Representative (with authorization letter)' : 'Barangay DRRM Staff (staff ID)' ?><br><?= !empty($r['claimed_by_name']) && !empty($r['delivered_at']) ? 'Date claimed: ' . e(date('F j, Y', strtotime($r['delivered_at']))) . ' &middot; ' . e(date('g:i A', strtotime($r['delivered_at']))) : 'Date claimed: ____________________' ?></div></div>
    <div><b>RELEASED BY:</b><div class="who"><?= e((string)($r['releaser'] ?? '')) ?></div><div class="line">SIGNATURE OVER PRINTED NAME</div><div class="when">DRRMC Staff, <?= e(rr_pickup_place()) ?></div></div>
    <?php endif; ?>
  </div>
</div>
<?php if (isset($_GET['autoprint'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script><?php endif; ?>
</body></html>
