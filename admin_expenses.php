<?php
require_once 'init.php';
require_login();
// Expense Review has been removed from the system: send everyone to their dashboard.
header('Location: ' . dashboard_for_role(current_user_role())); exit;
require_once 'budget_lib.php';
require_once 'budget_ai.php';

$is_head = is_role_in(['head_admin']);
$user_id = (int)$_SESSION['user_id'];
$schema_ok = budget_schema_ready($conn);
$tab = $_GET['tab'] ?? 'awaiting_review';
if (!in_array($tab, ['awaiting_review', 'approved', 'verified', 'returned', 'all'], true)) $tab = 'awaiting_review';
$brgy = isset($_GET['brgy']) && preg_match('/^\d{1,3}$/', $_GET['brgy']) ? $_GET['brgy'] : null;

$flash = $_SESSION['budget_flash'] ?? null;
unset($_SESSION['budget_flash']);

if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'admin_expenses.php?tab=' . urlencode($tab) . ($brgy !== null ? '&brgy=' . urlencode($brgy) : '');
    $upload_id = (int)($_POST['upload_id'] ?? 0);
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } elseif ($is_head) {
        // The Head allocates and monitors; expense decisions belong to the DRRMC Admins.
        audit_log('EXPENSE_DECISION_DENIED', 'packet #' . $upload_id, 'City Head attempted to record an expense decision', 'Failed');
        $_SESSION['budget_flash'] = ['type' => 'danger', 'text' => 'Expense decisions are made by DRRMC Admins. This attempt was logged.'];
    } elseif (($_POST['action'] ?? '') === 'decide') {
        $res = budget_record_review($conn, $user_id, $upload_id, (int)($_POST['ai_log_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['remarks'] ?? ''));
        $_SESSION['budget_flash'] = $res['ok']
            ? ['type' => 'ok', 'text' => 'Decision recorded for packet #' . $upload_id . '. The barangay can see the outcome.']
            : ['type' => 'danger', 'text' => $res['message']];
    } elseif (($_POST['action'] ?? '') === 'rerun') {
        $res = budget_verify_upload($conn, $upload_id);
        $_SESSION['budget_flash'] = $res['ok']
            ? ['type' => 'ok', 'text' => 'AI check re-run for packet #' . $upload_id . ': score ' . $res['score'] . '/100. The earlier result is kept in the log.']
            : ['type' => 'danger', 'text' => $res['message']];
    }
    header('Location: ' . $back);
    exit;
}

$counts = $schema_ok ? budget_packet_counts($conn) : ['awaiting_ai' => 0, 'awaiting_review' => 0, 'approved' => 0, 'verified' => 0, 'returned' => 0];
$counts['all'] = array_sum($counts);
$packets = $schema_ok ? budget_packets($conn, $brgy, $tab === 'awaiting_review' ? null : $tab, 40) : [];
if ($schema_ok && $tab === 'awaiting_review') {
    $packets = array_values(array_filter(budget_packets($conn, $brgy, null, 200), function ($p) { return in_array($p['status'], ['awaiting_review', 'awaiting_ai'], true); }));
}
$barangays = [];
if ($schema_ok) { $r = $conn->query('SELECT DISTINCT barangay FROM tbl_procurement_upload ORDER BY CAST(barangay AS UNSIGNED)'); while ($row = $r->fetch_assoc()) $barangays[] = $row['barangay']; }
$tabs = ['awaiting_review' => 'Awaiting review', 'approved' => 'Approved', 'verified' => 'Verified', 'returned' => 'Returned', 'all' => 'All'];
$tab_count = function ($k) use ($counts) { return $k === 'awaiting_review' ? $counts['awaiting_review'] + $counts['awaiting_ai'] : $counts[$k]; };

$match_class = ['matched' => 'green', 'price_mismatch' => 'red', 'quantity_mismatch' => 'amber', 'not_on_receipt' => 'red', 'not_in_excel' => 'amber', 'unauthorized_item' => 'red'];
$match_label = ['matched' => 'Matched', 'price_mismatch' => 'Price differs', 'quantity_mismatch' => 'Qty differs', 'not_on_receipt' => 'Not on receipt', 'not_in_excel' => 'Not in Excel', 'unauthorized_item' => 'Not allowed'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Expense Review</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-receipt" style="color:var(--primary);margin-right:8px;"></i>Expense Review</h2>
      <p style="margin:4px 0 0;color:#666;font-size:13px;">Each packet shows the Excel breakdown, the official receipt and the AI’s cross-check. You decide against that evidence; no amount can be changed.</p>
    </header>
    <main class="main-content" style="flex:1;overflow-y:auto;">
      <div class="bud-page">
        <?php if ($flash): ?><div class="bud-banner <?= e($flash['type']) ?>"><i class="fa-solid <?= $flash['type'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= e($flash['text']) ?></div></div><?php endif; ?>
        <?php if (!$schema_ok): ?><div class="bud-banner danger"><i class="fa-solid fa-database"></i><div>The budget tables are not installed yet. Import <strong>budget_transparency.sql</strong>.</div></div><?php endif; ?>
        <?php if ($is_head): ?><div class="bud-banner info"><i class="fa-solid fa-eye"></i><div>You are viewing as the <strong>City DRRMC Head</strong>. Expense decisions are made by DRRMC Admins; you monitor.</div></div><?php endif; ?>

        <div class="bud-toolbar">
          <div class="bud-tabs" style="margin:0;">
            <?php foreach ($tabs as $k => $label): ?><a href="?tab=<?= $k ?><?= $brgy !== null ? '&brgy=' . urlencode($brgy) : '' ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?> <span><?= (int)$tab_count($k) ?></span></a><?php endforeach; ?>
          </div>
          <form method="get" style="display:flex;align-items:center;gap:8px;">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <label for="brgySel" style="font-size:13px;font-weight:700;color:#344054;">Barangay</label>
            <select id="brgySel" name="brgy" onchange="this.form.submit()"><option value="">All</option><?php foreach ($barangays as $bg): ?><option value="<?= e($bg) ?>"<?= $brgy === $bg ? ' selected' : '' ?>><?= e(barangay_label($bg)) ?></option><?php endforeach; ?></select>
          </form>
        </div>

        <?php if (!$packets): ?>
          <section class="settings-card"><div class="bud-empty"><i class="fa-solid fa-inbox"></i><?= $tab === 'awaiting_review' ? 'Nothing is waiting for review.' : 'No packets in this list.' ?></div></section>
        <?php endif; ?>

        <?php foreach ($packets as $p):
            $d = budget_packet_detail($conn, (int)$p['upload_id']);
            $up = $d['upload']; $ai = $d['ai'];
            [$slabel, $sclass] = budget_status_pill($p['status']);
            [$vlabel, $vclass] = budget_verdict_pill($ai['verdict'] ?? null);
            $flags = $ai ? (json_decode($ai['flags'], true) ?: []) : [];
            $excel_lines = array_values(array_filter($d['lines'], function ($l) { return $l['source'] === 'excel'; }));
            $rcpt_lines = array_values(array_filter($d['lines'], function ($l) { return $l['source'] === 'receipt'; }));
            $unreadable = $ai && $ai['verdict'] === 'unreadable';
            $score = ($ai && !$unreadable) ? (int)$ai['match_score'] : 0;
            $ring = $score >= 90 ? '#198754' : ($score >= 60 ? '#eab308' : '#dc2626');
            $can_approve = $ai && $ai['verdict'] === 'verified_match' && !$ai['duplicate_of_upload_id'] && !(int)$ai['over_budget'];
            $can_verify = $ai && in_array($ai['verdict'], ['verified_match', 'partial_match'], true);
            $open = $p['status'] === 'awaiting_review';
        ?>
        <section class="settings-card bud-packet" id="packet<?= (int)$up['id'] ?>">
          <div class="bud-packet-head">
            <div><h3>Packet #<?= (int)$up['id'] ?> - <?= e(barangay_label($up['barangay'])) ?> - FY <?= (int)$up['fiscal_year'] ?></h3>
              <p>Submitted by <?= e($p['submitter']) ?> on <?= e(date('M j, Y g:i A', strtotime($up['uploaded_at']))) ?><?= $up['revision_of'] ? ' - revision of #' . (int)$up['revision_of'] : '' ?></p></div>
            <span class="pill <?= $sclass ?>"><?= e($slabel) ?></span>
          </div>

          <?php if (!$ai): ?>
            <div class="bud-empty"><i class="fa-solid fa-hourglass-half"></i>The AI check has not run for this packet yet.</div>
          <?php else: ?>
          <div class="bud-cols">
            <div>
              <div class="bud-score">
                <div class="bud-score-ring" style="--pct:<?= $score ?>;--ring:<?= $ring ?>"><div><?= $unreadable ? '&ndash;' : $score ?></div></div>
                <div><span class="pill <?= $vclass ?>"><?= e($vlabel) ?></span>
                  <p style="margin:8px 0 0;font-size:12px;color:#667085;"><?= $unreadable ? 'No score: the AI could not read this packet' : 'Match Verification Score /100' ?><br>run #<?= (int)$ai['run_no'] ?> - <?= e($ai['ai_provider']) ?> <?= e($ai['ai_model']) ?></p></div>
              </div>
              <dl class="bud-facts">
                <div><dt>Excel total</dt><dd><?= $ai['excel_total'] !== null ? e(peso($ai['excel_total'])) : '-' ?></dd></div>
                <div><dt>Receipt total</dt><dd><?= $ai['receipt_total'] !== null ? e(peso($ai['receipt_total'])) : '-' ?></dd></div>
                <div><dt>Vendor</dt><dd><?= $ai['receipt_vendor'] ? e($ai['receipt_vendor']) : '-' ?></dd></div>
                <div><dt>OR / invoice no.</dt><dd><?= $ai['receipt_number'] ? e($ai['receipt_number']) : '-' ?></dd></div>
                <div><dt>Receipt date</dt><dd><?= $ai['receipt_date'] ? e(date('M j, Y', strtotime($ai['receipt_date']))) : '-' ?></dd></div>
                <div><dt>Legibility</dt><dd><?= $ai['ocr_confidence'] !== null ? (int)$ai['ocr_confidence'] . '%' : '-' ?></dd></div>
                <div><dt>Allocated budget</dt><dd><?= e(peso($ai['allocated_budget'])) ?></dd></div>
                <div><dt>Remaining after this</dt><dd class="<?= (float)$ai['remaining_after'] < 0 ? 'bud-neg' : '' ?>"><?= e(peso($ai['remaining_after'])) ?></dd></div>
              </dl>
              <div class="bud-files">
                <a class="btn-ghost btn-sm" href="budget_file.php?id=<?= (int)$up['id'] ?>&f=excel"><i class="fa-solid fa-file-excel"></i> Excel</a>
                <a class="btn-ghost btn-sm" href="budget_file.php?id=<?= (int)$up['id'] ?>&f=receipt" target="_blank" rel="noopener"><i class="fa-solid fa-file-invoice"></i> Receipt</a>
              </div>
            </div>
            <div>
              <?php if ($flags): ?><ul class="bud-flags"><?php foreach ($flags as $f): ?><li class="sev-<?= e($f['severity'] ?? 'low') ?>"><b><?= e(str_replace('_', ' ', $f['code'] ?? '')) ?></b><?= e($f['detail'] ?? '') ?></li><?php endforeach; ?></ul>
              <?php else: ?><div class="bud-banner ok" style="margin-bottom:16px;"><i class="fa-solid fa-circle-check"></i><div>No discrepancies found. Totals and every line agree, the receipt is legible, and it is within budget.</div></div><?php endif; ?>
              <div class="bud-lines">
                <?php foreach ([['From the Excel breakdown', $excel_lines], ['Read from the receipt', $rcpt_lines]] as [$title, $set]): ?>
                <div><h4><?= e($title) ?></h4>
                  <?php if ($set): ?><table class="bud-table"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th><th></th></tr></thead><tbody>
                    <?php foreach ($set as $l): ?><tr title="<?= e($l['note'] ?? '') ?>"><td><?= e($l['item_name']) ?></td><td class="num"><?= $l['quantity'] !== null ? e(rtrim(rtrim(number_format((float)$l['quantity'], 3), '0'), '.')) : '-' ?></td><td class="num"><?= $l['line_total'] !== null ? e(number_format((float)$l['line_total'], 2)) : '-' ?></td><td><span class="pill <?= $match_class[$l['match_status']] ?? 'gray' ?>"><?= e($match_label[$l['match_status']] ?? $l['match_status']) ?></span></td></tr><?php endforeach; ?>
                  </tbody></table>
                  <?php else: ?><p class="settings-empty">Nothing could be read.</p><?php endif; ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($p['status'] === 'awaiting_review' && !$is_head && $ai): ?>
          <form method="post" class="bud-decision" data-can-approve="<?= $can_approve ? '1' : '0' ?>" data-can-verify="<?= $can_verify ? '1' : '0' ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="decide">
            <input type="hidden" name="upload_id" value="<?= (int)$up['id'] ?>">
            <input type="hidden" name="ai_log_id" value="<?= (int)$ai['id'] ?>">
            <p class="bud-hint"><?php
              if ($can_approve) echo 'Full match, within budget, not a duplicate: you may approve. Remarks are optional.';
              elseif ($can_verify) echo 'Partial match: you can verify it with remarks explaining the discrepancy, or return it. It cannot be approved outright.';
              else echo 'The AI could not confirm this packet (' . e(strtolower($vlabel)) . '). It can only be returned to the barangay.'; ?></p>
            <textarea name="remarks" placeholder="Remarks (required to verify with discrepancies or to return; at least 10 characters)"></textarea>
            <div class="bud-decision-buttons">
              <button type="submit" name="decision" value="approved" class="bud-btn-approve"<?= $can_approve ? '' : ' disabled' ?>>Approve</button>
              <button type="submit" name="decision" value="verified" class="bud-btn-verify"<?= $can_verify ? '' : ' disabled' ?>>Verify with remarks</button>
              <button type="submit" name="decision" value="returned" class="bud-btn-return">Return for revision</button>
              <button type="submit" name="action" value="rerun" class="bud-btn-rerun" formnovalidate title="Runs the AI check again; the earlier result is kept"><i class="fa-solid fa-rotate"></i> Re-run AI check</button>
            </div>
          </form>
          <?php elseif ($p['review_id']): ?>
          <div class="bud-decision"><strong><?= e(ucfirst($p['decision'])) ?></strong> by <?= e($p['reviewer_name']) ?> on <?= e(date('M j, Y g:i A', strtotime($p['decided_at']))) ?><?= $p['decision_remarks'] ? ' - "' . e($p['decision_remarks']) . '"' : '' ?></div>
          <?php endif; ?>
        </section>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
