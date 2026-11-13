<?php
// Printable "Tools and Equipment / Medical Supplies Report" in the barangay DRRMC paper format.
// ?id=<report id>            one report (barangay staff: own barangay only; DRRMC: any)
// ?month=YYYY-MM             barangay staff: every report of their barangay in that month, one page each
require_once 'init.php';
require_login();
require_once __DIR__ . '/forecast_engine.php';
require_once __DIR__ . '/item_tx_lib.php';

// The City Head only views reports and approves accounts; printing is for DRRMC officials and barangay staff.
if (is_role_in(['head_admin'])) { http_response_code(403); exit('Printing is not available for the City Head account.'); }
$is_office = is_role_in(['head_admin', 'admin', 'official']);
$me_stmt = $conn->prepare('SELECT barangay FROM users WHERE id = ? LIMIT 1');
$me_id = (int)$_SESSION['user_id'];
$me_stmt->bind_param('i', $me_id);
$me_stmt->execute();
$my_brgy = (string)(int)preg_replace('/\D+/', '', (string)($me_stmt->get_result()->fetch_assoc()['barangay'] ?? ''));
$me_stmt->close();

$base = "SELECT er.*, u.fullname, u.barangay AS brgy, u.address AS staff_address, u.profile_logo FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id";
$reports = [];
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $conn->prepare($base . ' WHERE er.id = ?' . ($is_office ? '' : ' AND u.barangay = ?') . ' LIMIT 1');
    if ($is_office) $st->bind_param('i', $id); else $st->bind_param('is', $id, $my_brgy);
    $st->execute();
    $reports = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} elseif (!$is_office && preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? ''))) {
    $from = $_GET['month'] . '-01';
    $st = $conn->prepare($base . " WHERE u.barangay = ? AND er.submitted_at >= ? AND er.submitted_at < DATE_ADD(?, INTERVAL 1 MONTH) ORDER BY er.submitted_at ASC");
    $st->bind_param('sss', $my_brgy, $from, $from);
    $st->execute();
    $reports = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
if (!$reports) { http_response_code(404); exit('Report not found.'); }
audit_log('REPORT_PRINTED', count($reports) === 1 ? 'report #' . $reports[0]['id'] : 'reports ' . e($_GET['month']), count($reports) . ' report(s) opened for printing');

$groups = [
    'NAME OF MEDICAL EQUIPMENT' => forecast_equipment_items(),
    'NAME OF MEDICAL SUPPLY' => forecast_items(),
];
// The barangay's report just before this one, to show what came IN and went OUT.
// Release & Obtained transactions (Audit Logs) that belong on this printout, per item:
// every transaction of the barangay in the report's month, up to this report's submission. For the barangay's
// latest report, transactions made after it was submitted are included too (they changed this report's stock).
function report_transactions(array $r, $prev) {
    global $conn;
    $b = (string)$r['brgy'];
    // The whole month of the report: from the 1st of its month up to the report (a new report in the same month
    // still shows the month's releases and obtained items).
    $from = date('Y-m-01 00:00:00', strtotime($r['submitted_at']));
    $st = $conn->prepare("SELECT er.id FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id
        WHERE u.barangay = ? AND (er.submitted_at > ? OR (er.submitted_at = ? AND er.id > ?)) LIMIT 1");
    $id = (int)$r['id'];
    $st->bind_param('sssi', $b, $r['submitted_at'], $r['submitted_at'], $id);
    $st->execute();
    $is_latest = !$st->get_result()->fetch_row();
    $st->close();
    $to = $is_latest ? date('Y-m-d H:i:s', time() + 60) : $r['submitted_at'];
    $st = $conn->prepare("SELECT item_key, txn_type, SUM(quantity) AS qty, MAX(created_at) AS last_at, COUNT(*) AS n,
        GROUP_CONCAT(DISTINCT counterpart ORDER BY created_at SEPARATOR ', ') AS who,
        GROUP_CONCAT(DISTINCT remarks ORDER BY created_at SEPARATOR '; ') AS why
        FROM item_transactions WHERE barangay = ? AND created_at >= ? AND created_at <= ? GROUP BY item_key, txn_type");
    $st->bind_param('sss', $b, $from, $to);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) $out[$t['item_key']][$t['txn_type']] = $t;
    $st->close();
    $st = $conn->prepare("SELECT item_key, txn_type, quantity, counterpart, remarks, created_at FROM item_transactions
        WHERE barangay = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at, id");
    $st->bind_param('sss', $b, $from, $to);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $t) $out[$t['item_key']]['list'][] = $t;
    $st->close();
    return $out;
}
function previous_report($r) {
    global $conn;
    $st = $conn->prepare("SELECT er.* FROM equipment_reports er INNER JOIN users u ON u.id = er.user_id
        WHERE u.barangay = ? AND (er.submitted_at < ? OR (er.submitted_at = ? AND er.id < ?)) ORDER BY er.submitted_at DESC, er.id DESC LIMIT 1");
    $b = (string)$r['brgy']; $id = (int)$r['id'];
    $st->bind_param('sssi', $b, $r['submitted_at'], $r['submitted_at'], $id);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    return $p;
}
// Which sections to print: ?sections=equipment,supplies,others (default: the whole report).
$section_keys = ['equipment' => 'NAME OF MEDICAL EQUIPMENT', 'supplies' => 'NAME OF MEDICAL SUPPLY'];
$want = array_flip(array_intersect(explode(',', (string)($_GET['sections'] ?? '')), ['equipment', 'supplies', 'others']));
if (!$want) $want = array_flip(['equipment', 'supplies', 'others']);
$print_groups = [];
foreach ($section_keys as $k => $title) if (isset($want[$k])) $print_groups[$title] = $groups[$title];
// One entry per line (kept on a single line so the columns stay aligned); a single plain value stays centred.
function rp_lines(array $lines) {
    $h = '';
    foreach ($lines as $l) $h .= '<div' . (count($lines) === 1 && strpos($l, '- ') !== 0 ? ' class="c"' : '') . ' title="' . e($l) . '">' . e($l) . '</div>';
    return $h;
}
function qty_of($row, $key) { $v = trim((string)($row[$key] ?? '')); return ($v === '' || !is_numeric($v)) ? null : max(0, (int)$v); }
$cdrrmd = 'assets/cdrrmd-logo.jpg';
?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Tools and Equipment / Medical Supplies Report</title>
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
  h1 { margin:34px 0 4px; text-align:center; font-size:21px; font-weight:800; letter-spacing:.2px; }
  .month { margin:0 0 4px; text-align:center; font-size:13px; font-weight:800; letter-spacing:.4px; }
  .sub { text-align:center; font-size:11.5px; margin:0 0 10px; }
  table { width:100%; border-collapse:collapse; font-size:11.5px; }
  th, td { border:1px solid #000; padding:5px 6px; text-align:center; vertical-align:middle; }
  td.lines { text-align:left; padding:3px 5px; }
  td.lines div { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; line-height:15px; height:15px; font-size:10.5px; }
  td.lines div.c { text-align:center; }
  table { table-layout:fixed; }
  th { font-weight:700; }
  td.name { text-transform:uppercase; }
  tr.grp th { padding:7px 6px; }
  .sign { display:grid; grid-template-columns:1fr 1fr; gap:1.2in; margin-top:40px; font-size:12.5px; }
  .sign b { display:block; margin-bottom:46px; }
  .sign .line { border-top:1px solid #000; padding-top:3px; font-size:10.5px; font-weight:700; }
  .sign .who { text-align:center; font-size:12px; text-transform:uppercase; height:16px; }
  .sign .when { margin-top:5px; font-size:10.5px; }
  @page { size:letter; margin:0; }
  @media print {
    body { background:#fff; } .toolbar { display:none; }
    .page { width:auto; min-height:0; margin:0; padding:0.4in 0.55in; box-shadow:none; page-break-after:always; }
    .page:last-child { page-break-after:auto; }
    .head img, .head .blank { width:0.85in; height:0.85in; } .head { grid-template-columns:0.95in 1fr 0.95in; }
    .head p { font-size:11.5px; line-height:1.3; }
    .month { font-size:11px; margin-bottom:2px; }
    h1 { margin:12px 0 2px; font-size:17px; } .sub { font-size:10px; margin-bottom:6px; }
    .month { margin-bottom:6px; }
    table { font-size:9.5px; } th, td { padding:1.6px 5px; } tr.grp th { padding:3px 5px; }
    .sign { margin-top:18px; font-size:11px; } .sign b { margin-bottom:26px; }
    td.lines { padding:1px 4px; } td.lines div { font-size:8.5px; line-height:10.5px; height:10.5px; }
  }
</style>
</head><body>
<?php if (!isset($_GET['inframe'])): ?><div class="toolbar"><button class="print" onclick="window.print()">🖨️ Print / Save as PDF</button><button class="close" onclick="window.close(); history.back();">Close</button></div><?php endif; ?>
<?php foreach ($reports as $r): $prev = previous_report($r); $tx = report_transactions($r, $prev); $date = date('m/d/Y', strtotime($r['submitted_at'])); $logo = (string)($r['profile_logo'] ?? ''); ?>
<div class="page">
  <div class="head">
    <?php if ($logo !== '' && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt="Barangay logo"><?php else: ?><span class="blank"></span><?php endif; ?>
    <div>
      <p>Republic of the Philippines</p>
      <p>CITY OF CALOOCAN</p>
      <p><?= e(strtoupper(barangay_label($r['brgy']))) ?> DISASTER RISK REDUCTION AND MANAGEMENT COUNCIL</p>
      <?php if (trim((string)$r['staff_address']) !== ''): ?><p><?= e($r['staff_address']) ?></p><?php endif; ?>
    </div>
    <img src="<?= e($cdrrmd) ?>" alt="CDRRMD logo">
  </div>
  <h1>TOOLS AND EQUIPMENT/ MEDICAL SUPPLIES REPORT</h1>
  <p class="month">FOR THE MONTH OF <?= e(strtoupper(date('F Y', strtotime($r['submitted_at'])))) ?></p>
  <table>
    <colgroup><col style="width:25%"><col style="width:6%"><col style="width:6%"><col style="width:6%"><col style="width:12%"><col style="width:24%"><col style="width:21%"></colgroup>
    <?php $first = true; foreach ($print_groups as $title => $items): ?>
      <tr class="grp"><th><?= $title ?></th><th>QTY</th><th>IN</th><th>OUT</th><th><?= $first ? 'DATE RELEASE / OBTAINED' : '' ?></th><th><?= $first ? 'RELEASED TO / RECEIVED FROM' : '' ?></th><th>REMARKS</th></tr>
      <?php $first = false; foreach ($items as $key => $label): $q = qty_of($r, $key); $p = $prev ? qty_of($prev, $key) : null;
        $t = $tx[$key] ?? null; $rem = (string)($r[$key . '_remarks'] ?? '');
        if ($t) {
          // Filled automatically from the Release & Obtained audit log.
          $in = isset($t['obtained']) ? (int)$t['obtained']['qty'] : ''; $out = isset($t['released']) ? (int)$t['released']['qty'] : '';
          $dates = array_filter([$t['released']['last_at'] ?? null, $t['obtained']['last_at'] ?? null]);
          $cnt = (int)($t['released']['n'] ?? 0) + (int)($t['obtained']['n'] ?? 0);
          // Each date, person and reason is listed once. A person's quantities are added up (e.g. "Out 20, In 12").
          $dates = $people = $reasons = [];
          foreach ($t['list'] ?? [] as $x) {
            $dates[date('m/d/Y', strtotime($x['created_at']))] = true;
            $name = trim((string)$x['counterpart']) !== '' ? trim($x['counterpart']) : '-';
            $k = mb_strtolower($name);
            if (!isset($people[$k])) $people[$k] = ['name' => $name, 'released' => 0, 'obtained' => 0];
            $people[$k][$x['txn_type']] += (int)$x['quantity'];
            if (trim((string)$x['remarks']) !== '') $reasons[mb_strtolower(trim($x['remarks']))] = trim($x['remarks']);
          }
          $when = array_map(function ($d) { return '- ' . $d; }, array_keys($dates));
          $who = array_map(function ($p) {
            return '- ' . $p['name'] . ' (' . implode(', ', array_filter([$p['released'] ? 'Out ' . $p['released'] : '', $p['obtained'] ? 'In ' . $p['obtained'] : ''])) . ')';
          }, array_values($people));
          $rem = array_merge(array_map(function ($r) { return '- ' . $r; }, array_values($reasons)), $rem !== '' ? [$rem] : []);
        } else {
          $in = ($q !== null && $p !== null && $q > $p) ? $q - $p : ''; $out = ($q !== null && $p !== null && $q < $p) ? $p - $q : '';
          $when = ($in !== '' || $out !== '') ? [$date] : []; $who = []; $rem = $rem !== '' ? [$rem] : [];
        } ?>
        <tr><td class="name"><?= e($label) ?></td><td><?= $q === null ? '' : $q ?></td><td><?= $in ?></td><td><?= $out ?></td><td class="lines"><?= rp_lines($when) ?></td><td class="lines"><?= rp_lines($who) ?></td><td class="lines"><?= rp_lines($rem) ?></td></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php $others = isset($want['others']) ? report_other_items($r) : []; if ($others): ?>
      <tr class="grp"><th>OTHER ITEMS</th><th>QTY</th><th>IN</th><th>OUT</th><th></th><th></th><th>REMARKS</th></tr>
      <?php foreach ($others as $o): ?><tr><td class="name"><?= e($o['name']) ?></td><td><?= (int)$o['qty'] ?></td><td></td><td></td><td></td><td></td><td><?= e($o['remarks']) ?></td></tr><?php endforeach; ?>
    <?php endif; ?>
  </table>
  <div class="sign">
    <div><b>ENDORSE BY:</b><div class="who"><?= e($r['fullname']) ?></div><div class="line">SIGNATURE OVER PRINTED NAME</div><div class="when">Date submitted: <?= e(date('F j, Y', strtotime($r['submitted_at']))) ?> &middot; <?= e(date('g:i A', strtotime($r['submitted_at']))) ?></div></div>
    <div><b>RECEIVED BY:</b><div class="who"></div><div class="line">SIGNATURE OVER PRINTED NAME</div><div class="when">Date received: ____________________</div></div>
  </div>
</div>
<?php endforeach; ?>
<?php if (isset($_GET['autoprint'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script><?php endif; ?>
</body></html>
