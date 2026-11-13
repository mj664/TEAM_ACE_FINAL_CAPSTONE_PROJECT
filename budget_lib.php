<?php
/**
 * Budget & expense transparency module - shared helpers.
 *
 * The rules themselves (who may write, immutability, hash chain, dual notification, decision rules) live in the
 * database: see budget_transparency.sql. Nothing in here can bypass them; this file only talks to that layer and
 * formats what comes back.
 */

const BUDGET_MAX_EXCEL_BYTES   = 5 * 1024 * 1024;
const BUDGET_MAX_RECEIPT_BYTES = 10 * 1024 * 1024;

// ---------------------------------------------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------------------------------------------

function budget_schema_ready(mysqli $conn): bool {
    $r = $conn->query("SHOW TABLES LIKE 'tbl_budget_allocation'");
    return $r && $r->num_rows > 0;
}

// Runs budget_transparency.sql (handles its DELIMITER lines). Returns ['ok' => bool, 'error' => string].
function budget_install_schema(mysqli $conn, string $file = ''): array {
    $file = $file !== '' ? $file : __DIR__ . '/budget_transparency.sql';
    $sql = @file_get_contents($file);
    if ($sql === false) return ['ok' => false, 'error' => 'budget_transparency.sql not found'];

    $delimiter = ';';
    $buffer = '';
    try {
        foreach (preg_split('/\r\n|\n|\r/', $sql) as $line) {
            $trim = trim($line);
            if ($buffer === '' && ($trim === '' || strpos($trim, '--') === 0)) continue;
            if (stripos($trim, 'DELIMITER ') === 0) { $delimiter = trim(substr($trim, 10)); continue; }
            if (strpos($trim, '--') === 0) continue; // comment line inside a statement
            $buffer .= $line . "\n";
            if (substr(rtrim($buffer), -strlen($delimiter)) === $delimiter) {
                $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                $buffer = '';
                if ($statement !== '') $conn->query($statement);
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => ''];
}

// ---------------------------------------------------------------------------------------------------------------
// Formatting / input
// ---------------------------------------------------------------------------------------------------------------

function peso($amount): string {
    $n = (float)$amount;
    return ($n < 0 ? '-' : '') . '₱' . number_format(abs($n), 2);
}

function budget_fiscal_year(): int { return (int)date('Y'); }

// "250,000.50", "₱ 250000", "PHP 1,000" -> "250000.50" / "250000.00"; null when not a valid money amount.
function budget_parse_amount($raw): ?string {
    $s = trim((string)$raw);
    $s = preg_replace('/^(?:php|₱|p)\s*/iu', '', $s);
    $neg = false;
    if ($s !== '' && $s[0] === '-') { $neg = true; $s = ltrim(substr($s, 1)); }
    $s = trim($s);
    // thousands separators only in the proper places (250,000.50 yes; 1,2,3 no - a typo must not become a different amount)
    if (strpos($s, ',') !== false && !preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $s)) return null;
    $s = str_replace(',', '', $s);
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) return null;
    $s = number_format((float)$s, 2, '.', '');
    if ((float)$s <= 0) return null;
    return ($neg ? '-' : '') . $s;
}

function budget_exception_message(Throwable $e): string {
    $m = $e->getMessage();
    // trigger SIGNAL texts are already written for people; only tidy the driver's wrapping
    $m = preg_replace('/^SQLSTATE\[[^\]]*\]:?\s*/', '', $m);
    if (stripos($m, 'Duplicate entry') !== false) return 'That entry was just recorded by someone else. Please refresh and try again.';
    if (stripos($m, 'CONSTRAINT') !== false && stripos($m, 'failed') !== false) return 'Some of the values were not accepted (amount, year or file type).';
    return $m;
}

function budget_status_pill(string $status): array {
    $map = [
        'awaiting_ai'     => ['Checking with AI', 'gray'],
        'awaiting_review' => ['Awaiting review', 'amber'],
        'approved'        => ['Approved', 'green'],
        'verified'        => ['Verified', 'blue'],
        'returned'        => ['Returned', 'red'],
    ];
    return $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'gray'];
}

function budget_verdict_pill(?string $verdict): array {
    $map = [
        'verified_match' => ['Full match', 'green'],
        'partial_match'  => ['Partial match', 'amber'],
        'mismatch'       => ['Mismatch', 'red'],
        'unreadable'     => ['Unreadable', 'gray'],
    ];
    return $map[$verdict ?? ''] ?? ['Not checked', 'gray'];
}

// ---------------------------------------------------------------------------------------------------------------
// Ledger / balances / notices
// ---------------------------------------------------------------------------------------------------------------

// One row per barangay with a ledger entry in that year. $barangay limits it to one barangay.
function budget_balances(mysqli $conn, int $year, ?string $barangay = null): array {
    $sql = 'SELECT b.barangay, b.fiscal_year, b.allocated, b.spent, b.pending_review, b.remaining FROM v_budget_balance b WHERE b.fiscal_year = ?';
    if ($barangay !== null) $sql .= ' AND b.barangay = ?';
    $sql .= ' ORDER BY CAST(b.barangay AS UNSIGNED), b.barangay';
    $stmt = $conn->prepare($sql);
    if ($barangay !== null) $stmt->bind_param('is', $year, $barangay); else $stmt->bind_param('i', $year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function budget_years(mysqli $conn): array {
    $years = [budget_fiscal_year()];
    $r = $conn->query('SELECT DISTINCT fiscal_year FROM tbl_budget_allocation ORDER BY fiscal_year DESC');
    if ($r) while ($row = $r->fetch_assoc()) $years[] = (int)$row['fiscal_year'];
    $years = array_values(array_unique($years));
    rsort($years);
    return $years;
}

function budget_ledger(mysqli $conn, int $year, int $limit = 40, ?string $barangay = null): array {
    $sql = 'SELECT a.id, a.barangay, a.seq, a.entry_type, a.amount, a.reason, a.allocated_at, a.row_hash, u.fullname AS head_name,
                   i.link_ok, i.hash_ok
              FROM tbl_budget_allocation a
              JOIN users u ON u.id = a.allocated_by
              JOIN v_budget_ledger_integrity i ON i.id = a.id
             WHERE a.fiscal_year = ?';
    if ($barangay !== null) $sql .= ' AND a.barangay = ?';
    $sql .= ' ORDER BY a.id DESC LIMIT ' . (int)$limit;
    $stmt = $conn->prepare($sql);
    if ($barangay !== null) $stmt->bind_param('is', $year, $barangay); else $stmt->bind_param('i', $year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function budget_tampered_rows(mysqli $conn): int {
    $r = $conn->query('SELECT COUNT(*) AS n FROM v_budget_ledger_integrity WHERE NOT (link_ok AND hash_ok)');
    $n = $r ? (int)$r->fetch_assoc()['n'] : 0;
    $r = $conn->query('SELECT COUNT(*) AS n FROM v_ai_log_integrity WHERE NOT (link_ok AND hash_ok)');
    return $n + ($r ? (int)$r->fetch_assoc()['n'] : 0);
}

function budget_notices(mysqli $conn, int $user_id, int $limit = 10): array {
    $stmt = $conn->prepare('SELECT id, message, amount, barangay, fiscal_year, created_at, read_at, recipient_role
                              FROM tbl_budget_notification WHERE recipient_user_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function budget_unread_notices(mysqli $conn, int $user_id): int {
    $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM tbl_budget_notification WHERE recipient_user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

function budget_mark_notices_read(mysqli $conn, int $user_id): void {
    $stmt = $conn->prepare('UPDATE tbl_budget_notification SET read_at = NOW() WHERE recipient_user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

// Head only (the database enforces it again). Returns ['ok', 'message', 'id', 'staff', 'admins'].
function budget_allocate(mysqli $conn, int $head_id, string $barangay, int $year, string $amount, string $type = 'allocation', ?int $adjusts_id = null, ?string $reason = null): array {
    try {
        $stmt = $conn->prepare('INSERT INTO tbl_budget_allocation (barangay, fiscal_year, entry_type, amount, adjusts_id, reason, allocated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sissisi', $barangay, $year, $type, $amount, $adjusts_id, $reason, $head_id);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();

        $staff = $admins = 0;
        $r = $conn->query('SELECT recipient_role, COUNT(*) AS n FROM tbl_budget_notification WHERE allocation_id = ' . $id . ' GROUP BY recipient_role');
        while ($row = $r->fetch_assoc()) { if ($row['recipient_role'] === 'barangay_staff') $staff = (int)$row['n']; else $admins = (int)$row['n']; }
        return ['ok' => true, 'message' => '', 'id' => $id, 'staff' => $staff, 'admins' => $admins];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => budget_exception_message($e), 'id' => 0, 'staff' => 0, 'admins' => 0];
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Expense packets
// ---------------------------------------------------------------------------------------------------------------

function budget_storage_dir(string $sub = ''): string {
    $root = __DIR__ . '/storage';
    $dir = $root . '/expenses' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($root . '/.htaccess')) @file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return $dir;
}

// $file is one entry of $_FILES. Returns ['ok', 'error', 'ext', 'mime'].
function budget_check_upload(array $file, string $kind): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => $kind === 'excel' ? 'Attach the purchase breakdown (.xlsx or .csv).' : 'Attach the official receipt or invoice.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'A file could not be uploaded (' . ($kind === 'excel' ? 'breakdown' : 'receipt') . '). Check its size and try again.'];
    $size = (int)$file['size'];
    $name = (string)$file['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $head = (string)@file_get_contents($file['tmp_name'], false, null, 0, 8);

    if ($kind === 'excel') {
        if ($size <= 0 || $size > BUDGET_MAX_EXCEL_BYTES) return ['ok' => false, 'error' => 'The breakdown file must be smaller than 5 MB.'];
        if ($ext === 'xlsx') {
            if (substr($head, 0, 4) !== "PK\x03\x04") return ['ok' => false, 'error' => 'That .xlsx file is not a valid Excel workbook.'];
            return ['ok' => true, 'error' => '', 'ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }
        if ($ext === 'csv') {
            $sample = (string)@file_get_contents($file['tmp_name'], false, null, 0, 4096);
            if (strpos($sample, "\0") !== false) return ['ok' => false, 'error' => 'That .csv file is not plain text.'];
            return ['ok' => true, 'error' => '', 'ext' => 'csv', 'mime' => 'text/csv'];
        }
        return ['ok' => false, 'error' => 'The breakdown must be an Excel (.xlsx) or .csv file.'];
    }

    if ($size <= 0 || $size > BUDGET_MAX_RECEIPT_BYTES) return ['ok' => false, 'error' => 'The receipt file must be smaller than 10 MB.'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) return ['ok' => false, 'error' => 'The receipt must be a JPG, PNG, WEBP image or a PDF.'];
    return ['ok' => true, 'error' => '', 'ext' => $allowed[$mime], 'mime' => $mime];
}

// Stores both files, records the packet (the DB checks the submitter / barangay / budget), returns ['ok','error','id'].
function budget_submit_packet(mysqli $conn, array $user, int $year, array $excel_file, array $receipt_file, ?int $revision_of = null): array {
    $excel = budget_check_upload($excel_file, 'excel');
    if (!$excel['ok']) return ['ok' => false, 'error' => $excel['error'], 'id' => 0];
    $receipt = budget_check_upload($receipt_file, 'receipt');
    if (!$receipt['ok']) return ['ok' => false, 'error' => $receipt['error'], 'id' => 0];

    $dir = budget_storage_dir();
    $stamp = bin2hex(random_bytes(6));
    $excel_rel  = 'expenses/exp_' . $stamp . '_breakdown.' . $excel['ext'];
    $rcpt_rel   = 'expenses/exp_' . $stamp . '_receipt.' . $receipt['ext'];
    $excel_abs  = __DIR__ . '/storage/' . $excel_rel;
    $rcpt_abs   = __DIR__ . '/storage/' . $rcpt_rel;
    if (!move_uploaded_file($excel_file['tmp_name'], $excel_abs) || !move_uploaded_file($receipt_file['tmp_name'], $rcpt_abs)) {
        @unlink($excel_abs); @unlink($rcpt_abs);
        return ['ok' => false, 'error' => 'The files could not be saved. Please try again.', 'id' => 0];
    }
    $excel_hash = hash_file('sha256', $excel_abs);
    $rcpt_hash  = hash_file('sha256', $rcpt_abs);
    $excel_name = mb_substr(basename((string)$excel_file['name']), 0, 250);
    $rcpt_name  = mb_substr(basename((string)$receipt_file['name']), 0, 250);
    $excel_size = (int)filesize($excel_abs);
    $rcpt_size  = (int)filesize($rcpt_abs);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $barangay = (string)$user['barangay'];
    $uid = (int)$user['id'];

    try {
        $stmt = $conn->prepare('INSERT INTO tbl_procurement_upload
            (barangay, fiscal_year, submitted_by, revision_of, excel_path, excel_original_name, excel_sha256, excel_size,
             receipt_path, receipt_original_name, receipt_mime, receipt_sha256, receipt_size, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('siiisssissssis', $barangay, $year, $uid, $revision_of, $excel_rel, $excel_name, $excel_hash, $excel_size,
            $rcpt_rel, $rcpt_name, $receipt['mime'], $rcpt_hash, $rcpt_size, $ip);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        @unlink($excel_abs); @unlink($rcpt_abs);
        return ['ok' => false, 'error' => budget_exception_message($e), 'id' => 0];
    }
    audit_log('EXPENSE_SUBMITTED', 'Barangay ' . $barangay, 'packet #' . $id . '; FY ' . $year . '; excel_sha256=' . substr($excel_hash, 0, 16) . '; receipt_sha256=' . substr($rcpt_hash, 0, 16));
    return ['ok' => true, 'error' => '', 'id' => $id];
}

// Packets with their current state. $barangay limits to one barangay; $status filters ('awaiting_review', ...).
function budget_packets(mysqli $conn, ?string $barangay = null, ?string $status = null, int $limit = 60): array {
    $sql = 'SELECT s.*, su.fullname AS submitter, u.excel_original_name, u.receipt_original_name, u.receipt_mime,
                   ru.fullname AS reviewer_name, r.remarks AS decision_remarks
              FROM v_procurement_status s
              JOIN tbl_procurement_upload u ON u.id = s.upload_id
              JOIN users su ON su.id = s.submitted_by
              LEFT JOIN tbl_procurement_review r ON r.id = s.review_id
              LEFT JOIN users ru ON ru.id = r.reviewer_id
             WHERE 1 = 1';
    $types = ''; $params = [];
    if ($barangay !== null) { $sql .= ' AND s.barangay = ?'; $types .= 's'; $params[] = $barangay; }
    if ($status !== null && $status !== 'all') { $sql .= ' AND s.status = ?'; $types .= 's'; $params[] = $status; }
    $sql .= ' ORDER BY s.upload_id DESC LIMIT ' . (int)$limit;
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function budget_packet_counts(mysqli $conn, ?string $barangay = null): array {
    $sql = 'SELECT status, COUNT(*) AS n FROM v_procurement_status' . ($barangay !== null ? ' WHERE barangay = ?' : '') . ' GROUP BY status';
    $stmt = $conn->prepare($sql);
    if ($barangay !== null) $stmt->bind_param('s', $barangay);
    $stmt->execute();
    $out = ['awaiting_ai' => 0, 'awaiting_review' => 0, 'approved' => 0, 'verified' => 0, 'returned' => 0];
    foreach ($stmt->get_result() as $row) $out[$row['status']] = (int)$row['n'];
    $stmt->close();
    return $out;
}

// Full AI evidence for one packet: latest log row, all line items of that run, the upload itself.
function budget_packet_detail(mysqli $conn, int $upload_id): ?array {
    $stmt = $conn->prepare('SELECT * FROM tbl_procurement_upload WHERE id = ?');
    $stmt->bind_param('i', $upload_id);
    $stmt->execute();
    $upload = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$upload) return null;

    $stmt = $conn->prepare('SELECT * FROM tbl_ai_verification_log WHERE upload_id = ? ORDER BY run_no DESC LIMIT 1');
    $stmt->bind_param('i', $upload_id);
    $stmt->execute();
    $ai = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $lines = [];
    if ($ai) {
        $stmt = $conn->prepare('SELECT * FROM tbl_procurement_line_item WHERE ai_log_id = ? ORDER BY source, line_no');
        $stmt->bind_param('i', $ai['id']);
        $stmt->execute();
        $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return ['upload' => $upload, 'ai' => $ai, 'lines' => $lines];
}

function budget_record_review(mysqli $conn, int $reviewer_id, int $upload_id, int $ai_log_id, string $decision, string $remarks): array {
    if (!in_array($decision, ['approved', 'verified', 'returned'], true)) return ['ok' => false, 'message' => 'Unknown decision.'];
    try {
        $stmt = $conn->prepare('INSERT INTO tbl_procurement_review (upload_id, ai_log_id, reviewer_id, decision, remarks) VALUES (?, ?, ?, ?, ?)');
        $remarks = trim($remarks) === '' ? null : trim($remarks);
        $stmt->bind_param('iiiss', $upload_id, $ai_log_id, $reviewer_id, $decision, $remarks);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => budget_exception_message($e)];
    }
    audit_log('EXPENSE_' . strtoupper($decision), 'packet #' . $upload_id, 'ai_log #' . $ai_log_id . ($remarks ? '; remarks: ' . mb_substr($remarks, 0, 200) : ''));
    return ['ok' => true, 'message' => ''];
}
