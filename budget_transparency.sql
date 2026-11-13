-- =====================================================================================
--  DRRMC Budget & Expense Transparency Module  -  schema update (MariaDB 10.4 / MySQL 8)
--  Run once against the `capstone_db` database (phpMyAdmin > Import, or `mysql capstone_db < budget_transparency.sql`).
--  Safe to re-run: tables use IF NOT EXISTS, triggers/views are dropped and recreated.
--
--  Design rule: money and evidence are APPEND-ONLY.
--    * Nothing here can be UPDATEd or DELETEd (triggers raise IMMUTABLE_RECORD).  Mistakes are fixed by a new
--      row (an adjustment / a new upload / a new AI run), never by editing history.
--    * Who may write is checked in the database itself (triggers look the user's role up in `users`), so a bug
--      or a hand-written query in the PHP layer cannot let an Admin or a Barangay User touch budget figures.
--    * Budget ledger and AI verification log are hash-chained (prev_hash -> row_hash) so any later tampering
--      with a stored row is detectable (see the v_*_integrity views at the bottom).
-- =====================================================================================

-- ---------------------------------------------------------------------------------
-- 1. tbl_budget_allocation : the budget ledger.  ONLY the City DRRMC Head (users.role = 'head_admin') may insert.
--    One row = one allocation or one signed adjustment.  Budget of a barangay for a year = SUM(amount).
-- ---------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_budget_allocation (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  barangay      VARCHAR(100)     NOT NULL,                         -- same value as users.barangay
  fiscal_year   SMALLINT UNSIGNED NOT NULL,
  seq           INT UNSIGNED     NOT NULL DEFAULT 0,               -- per (barangay, fiscal_year), set by trigger
  entry_type    ENUM('allocation','adjustment') NOT NULL DEFAULT 'allocation',
  amount        DECIMAL(14,2)    NOT NULL,                         -- PHP; adjustments may be negative
  adjusts_id    BIGINT UNSIGNED  NULL,                             -- adjustment -> the allocation it corrects
  reason        VARCHAR(500)     NULL,                             -- mandatory for adjustments
  allocated_by  INT(11)          NOT NULL,                         -- the Head's users.id
  allocated_at  DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6),  -- overwritten with server time by trigger
  prev_hash     CHAR(64)         NOT NULL DEFAULT '',
  row_hash      CHAR(64)         NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_ba_chain (barangay, fiscal_year, seq),
  KEY idx_ba_barangay (barangay, fiscal_year),
  KEY idx_ba_by (allocated_by),
  CONSTRAINT fk_ba_user     FOREIGN KEY (allocated_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ba_adjusts  FOREIGN KEY (adjusts_id)   REFERENCES tbl_budget_allocation (id) ON DELETE RESTRICT,
  CONSTRAINT ck_ba_amount   CHECK (amount <> 0),
  CONSTRAINT ck_ba_positive CHECK (entry_type = 'adjustment' OR amount > 0),
  CONSTRAINT ck_ba_year     CHECK (fiscal_year BETWEEN 2000 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------------
-- 2. tbl_budget_notification : the dual broadcast.  One row per recipient, created by the database the moment an
--    allocation row is written (barangay staff = recipient, DRRMC Admins = monitors).  Only read_at may change.
-- ---------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_budget_notification (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  allocation_id     BIGINT UNSIGNED NOT NULL,
  recipient_user_id INT(11)         NOT NULL,
  recipient_role    ENUM('barangay_staff','admin_monitor') NOT NULL,
  barangay          VARCHAR(100)    NOT NULL,
  fiscal_year       SMALLINT UNSIGNED NOT NULL,
  amount            DECIMAL(14,2)   NOT NULL,                      -- exact figure copied from the ledger row
  message           VARCHAR(500)    NOT NULL,
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  read_at           DATETIME        NULL,                          -- the ONLY mutable column
  PRIMARY KEY (id),
  UNIQUE KEY uq_bn_once (allocation_id, recipient_user_id),
  KEY idx_bn_recipient (recipient_user_id, read_at),
  CONSTRAINT fk_bn_alloc FOREIGN KEY (allocation_id)     REFERENCES tbl_budget_allocation (id) ON DELETE RESTRICT,
  CONSTRAINT fk_bn_user  FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------------
-- 3. tbl_procurement_upload : what a Barangay User submits to account for spending.
--    Deliberately has NO price / item / amount columns - the only inputs are the two files (zero manual encoding).
-- ---------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_procurement_upload (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  barangay             VARCHAR(100)    NOT NULL,
  fiscal_year          SMALLINT UNSIGNED NOT NULL,
  submitted_by         INT(11)         NOT NULL,
  revision_of          BIGINT UNSIGNED NULL,                       -- set when re-submitting a packet that was returned
  excel_path           VARCHAR(255)    NOT NULL,
  excel_original_name  VARCHAR(255)    NOT NULL,
  excel_sha256         CHAR(64)        NOT NULL,                   -- fingerprint of the stored file: a swapped file is detectable
  excel_size           INT UNSIGNED    NOT NULL,
  receipt_path         VARCHAR(255)    NOT NULL,
  receipt_original_name VARCHAR(255)   NOT NULL,
  receipt_mime         VARCHAR(60)     NOT NULL,
  receipt_sha256       CHAR(64)        NOT NULL,
  receipt_size         INT UNSIGNED    NOT NULL,
  ip_address           VARCHAR(45)     NULL,
  uploaded_at          DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_pu_barangay (barangay, fiscal_year),
  KEY idx_pu_receipt_hash (receipt_sha256),                        -- duplicate-receipt detection (same file reused)
  KEY idx_pu_excel_hash (excel_sha256),
  KEY idx_pu_user (submitted_by),
  CONSTRAINT fk_pu_user     FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pu_revision FOREIGN KEY (revision_of)  REFERENCES tbl_procurement_upload (id) ON DELETE RESTRICT,
  CONSTRAINT ck_pu_excel_ext  CHECK (LOWER(excel_original_name) REGEXP '\\.(xlsx|csv)$'),
  CONSTRAINT ck_pu_mime       CHECK (receipt_mime IN ('image/jpeg','image/png','image/webp','application/pdf')),
  CONSTRAINT ck_pu_excel_hash CHECK (CHAR_LENGTH(excel_sha256) = 64),
  CONSTRAINT ck_pu_rcpt_hash  CHECK (CHAR_LENGTH(receipt_sha256) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------------
-- 4. tbl_ai_verification_log : one row per AI run over an upload (Excel vs receipt vs allocated budget).
--    Written only by the AI engine (system actor).  Hash-chained per upload.  No human can edit a score.
-- ---------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_ai_verification_log (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  upload_id             BIGINT UNSIGNED NOT NULL,
  run_no                INT UNSIGNED    NOT NULL DEFAULT 0,        -- set by trigger (1, 2, ...)
  ai_provider           VARCHAR(30)     NOT NULL,                  -- 'gemini' | 'anthropic' | ... (engine is provider-agnostic)
  ai_model              VARCHAR(80)     NOT NULL,
  engine_version        VARCHAR(30)     NOT NULL,                  -- version of the matching rules / scoring formula
  -- what was read from the Excel sheet
  excel_line_count      INT UNSIGNED    NULL,
  excel_total           DECIMAL(14,2)   NULL,
  -- what was read from the official receipt
  receipt_line_count    INT UNSIGNED    NULL,
  receipt_total         DECIMAL(14,2)   NULL,
  receipt_vendor        VARCHAR(190)    NULL,
  receipt_number        VARCHAR(80)     NULL,
  receipt_date          DATE            NULL,
  ocr_confidence        DECIMAL(5,2)    NULL,                      -- 0-100, engine-reported legibility
  -- budget snapshot at the moment of the run (so the proof never changes later)
  allocated_budget      DECIMAL(14,2)   NOT NULL,
  spent_before          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  remaining_after       DECIMAL(14,2)   NOT NULL,
  over_budget           TINYINT(1)      NOT NULL DEFAULT 0,
  -- result
  match_score           TINYINT UNSIGNED NOT NULL,                 -- 0-100 Match Verification Score
  verdict               ENUM('verified_match','partial_match','mismatch','unreadable') NOT NULL,
  duplicate_of_upload_id BIGINT UNSIGNED NULL,                     -- same receipt (hash or OR number) already used
  flags                 LONGTEXT        NOT NULL,                  -- JSON array of {code, severity, detail}
  response_sha256       CHAR(64)        NOT NULL,                  -- hash of the raw AI response kept in storage
  created_at            DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  prev_hash             CHAR(64)        NOT NULL DEFAULT '',
  row_hash              CHAR(64)        NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_run (upload_id, run_no),
  KEY idx_ai_verdict (verdict, match_score),
  CONSTRAINT fk_ai_upload FOREIGN KEY (upload_id) REFERENCES tbl_procurement_upload (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_dup    FOREIGN KEY (duplicate_of_upload_id) REFERENCES tbl_procurement_upload (id) ON DELETE RESTRICT,
  CONSTRAINT ck_ai_score  CHECK (match_score <= 100),
  CONSTRAINT ck_ai_flags  CHECK (JSON_VALID(flags))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Line items the AI read (Excel side and receipt side) and how each one was matched.  Append-only.
CREATE TABLE IF NOT EXISTS tbl_procurement_line_item (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ai_log_id     BIGINT UNSIGNED NOT NULL,
  upload_id     BIGINT UNSIGNED NOT NULL,
  source        ENUM('excel','receipt') NOT NULL,
  line_no       INT UNSIGNED    NOT NULL,
  item_name     VARCHAR(255)    NOT NULL,
  quantity      DECIMAL(12,3)   NULL,
  unit_cost     DECIMAL(14,2)   NULL,
  line_total    DECIMAL(14,2)   NULL,
  match_status  ENUM('matched','price_mismatch','quantity_mismatch','not_on_receipt','not_in_excel','unauthorized_item') NOT NULL,
  note          VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pli (ai_log_id, source, line_no),
  KEY idx_pli_upload (upload_id),
  CONSTRAINT fk_pli_ai     FOREIGN KEY (ai_log_id) REFERENCES tbl_ai_verification_log (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pli_upload FOREIGN KEY (upload_id) REFERENCES tbl_procurement_upload (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------------
-- 5. tbl_procurement_review : the DRRMC Admin's decision.  It has NO monetary columns, so an Admin structurally
--    cannot alter an amount - they can only record approved / verified / returned against the AI proof.
-- ---------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_procurement_review (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  upload_id   BIGINT UNSIGNED NOT NULL,
  ai_log_id   BIGINT UNSIGNED NOT NULL,                            -- the exact AI proof the decision was based on
  reviewer_id INT(11)         NOT NULL,
  decision    ENUM('approved','verified','returned') NOT NULL,
  remarks     VARCHAR(1000)   NULL,
  decided_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_pr_upload (upload_id, id),
  CONSTRAINT fk_pr_upload FOREIGN KEY (upload_id)   REFERENCES tbl_procurement_upload (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pr_ai     FOREIGN KEY (ai_log_id)   REFERENCES tbl_ai_verification_log (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pr_user   FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================================
--  TRIGGERS
-- =====================================================================================
DELIMITER $$

-- ------------------------------ tbl_budget_allocation ------------------------------
DROP TRIGGER IF EXISTS trg_ba_bi $$
CREATE TRIGGER trg_ba_bi BEFORE INSERT ON tbl_budget_allocation FOR EACH ROW
BEGIN
  DECLARE v_role   VARCHAR(30);
  DECLARE v_status VARCHAR(20);
  DECLARE v_seq    INT UNSIGNED;
  DECLARE v_prev   CHAR(64);
  DECLARE v_total  DECIMAL(16,2);

  -- WRITE PERMISSION: only an approved City DRRMC Head. Admins and Barangay Users are rejected here.
  SELECT role, status INTO v_role, v_status FROM users WHERE id = NEW.allocated_by;
  IF v_role IS NULL OR v_role <> 'head_admin' OR v_status <> 'approved' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FORBIDDEN: only the City DRRMC Head may write budget allocations';
  END IF;

  IF NEW.entry_type = 'adjustment' THEN
    IF NEW.adjusts_id IS NULL OR CHAR_LENGTH(TRIM(COALESCE(NEW.reason, ''))) < 10 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An adjustment must reference the entry it corrects and give a reason (10+ characters)';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM tbl_budget_allocation a WHERE a.id = NEW.adjusts_id AND a.barangay = NEW.barangay AND a.fiscal_year = NEW.fiscal_year) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The adjusted entry must belong to the same barangay and fiscal year';
    END IF;
  ELSE
    SET NEW.adjusts_id = NULL;
  END IF;

  SELECT COALESCE(SUM(amount), 0) INTO v_total FROM tbl_budget_allocation WHERE barangay = NEW.barangay AND fiscal_year = NEW.fiscal_year;
  IF v_total + NEW.amount < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An adjustment cannot take the barangay budget below zero';
  END IF;

  -- server-controlled time, sequence and hash chain (per barangay + fiscal year)
  SELECT COALESCE(MAX(seq), 0) + 1 INTO v_seq FROM tbl_budget_allocation WHERE barangay = NEW.barangay AND fiscal_year = NEW.fiscal_year;
  SELECT row_hash INTO v_prev FROM tbl_budget_allocation WHERE barangay = NEW.barangay AND fiscal_year = NEW.fiscal_year ORDER BY seq DESC LIMIT 1;
  SET NEW.seq          = v_seq;
  SET NEW.allocated_at = NOW(6);
  SET NEW.prev_hash    = COALESCE(v_prev, REPEAT('0', 64));
  SET NEW.row_hash     = SHA2(CONCAT_WS('|', NEW.prev_hash, NEW.barangay, NEW.fiscal_year, NEW.seq, NEW.entry_type, NEW.amount,
                                        NEW.allocated_by, COALESCE(NEW.adjusts_id, ''), COALESCE(NEW.reason, ''), NEW.allocated_at), 256);
END $$

-- AUTOMATED DUAL NOTIFICATION + AUDIT RECORD: fired by the database, in the same transaction as the allocation.
DROP TRIGGER IF EXISTS trg_ba_ai $$
CREATE TRIGGER trg_ba_ai AFTER INSERT ON tbl_budget_allocation FOR EACH ROW
BEGIN
  DECLARE v_what VARCHAR(40);
  SET v_what = IF(NEW.entry_type = 'adjustment', 'adjusted the budget of', 'allocated');

  -- a) Barangay Staff (recipient): every approved staff account of that barangay
  INSERT INTO tbl_budget_notification (allocation_id, recipient_user_id, recipient_role, barangay, fiscal_year, amount, message)
  SELECT NEW.id, u.id, 'barangay_staff', NEW.barangay, NEW.fiscal_year, NEW.amount,
         CONCAT('The City DRRMC Head ', v_what, ' Barangay ', NEW.barangay, ' - FY ', NEW.fiscal_year, ': PHP ',
                FORMAT(NEW.amount, 2), '. Ledger ref #', NEW.id, '. Expenses must be reported with your Excel breakdown and official receipt.')
    FROM users u
   WHERE u.role = 'user' AND u.status = 'approved' AND u.barangay = NEW.barangay;

  -- b) DRRMC Admins / Audit Officers (monitors): every approved admin
  INSERT INTO tbl_budget_notification (allocation_id, recipient_user_id, recipient_role, barangay, fiscal_year, amount, message)
  SELECT NEW.id, u.id, 'admin_monitor', NEW.barangay, NEW.fiscal_year, NEW.amount,
         CONCAT('AUDIT NOTICE: the City DRRMC Head ', v_what, ' Barangay ', NEW.barangay, ' - FY ', NEW.fiscal_year, ': PHP ',
                FORMAT(NEW.amount, 2), '. Ledger ref #', NEW.id, ' (hash ', LEFT(NEW.row_hash, 12), '...).')
    FROM users u
   WHERE u.role IN ('admin', 'official') AND u.status = 'approved';

  -- c) tbl_audit_log: exact timestamp, Head user id, target barangay, allocated amount
  INSERT INTO tbl_audit_log (user_id, actor_label, actor_role, action, target, details, status, ip_address)
  SELECT u.id, u.email, 'head_admin', IF(NEW.entry_type = 'adjustment', 'BUDGET_ADJUSTED', 'BUDGET_ALLOCATED'),
         CONCAT('Barangay ', NEW.barangay),
         CONCAT('ledger_id=', NEW.id, '; head_user_id=', NEW.allocated_by, '; barangay=', NEW.barangay, '; fiscal_year=', NEW.fiscal_year,
                '; amount=', NEW.amount, '; at=', DATE_FORMAT(NEW.allocated_at, '%Y-%m-%d %H:%i:%s.%f'), '; row_hash=', NEW.row_hash),
         'Success', NULL
    FROM users u WHERE u.id = NEW.allocated_by;
END $$

DROP TRIGGER IF EXISTS trg_ba_bu $$
CREATE TRIGGER trg_ba_bu BEFORE UPDATE ON tbl_budget_allocation FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: budget ledger rows cannot be modified - post an adjustment instead' $$
DROP TRIGGER IF EXISTS trg_ba_bd $$
CREATE TRIGGER trg_ba_bd BEFORE DELETE ON tbl_budget_allocation FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: budget ledger rows cannot be deleted' $$

-- ------------------------------ tbl_budget_notification ------------------------------
DROP TRIGGER IF EXISTS trg_bn_bu $$
CREATE TRIGGER trg_bn_bu BEFORE UPDATE ON tbl_budget_notification FOR EACH ROW
BEGIN
  -- only "read_at" may change (marking a notice as read); the amount and text can never be altered
  IF NOT (NEW.allocation_id <=> OLD.allocation_id AND NEW.recipient_user_id <=> OLD.recipient_user_id
          AND NEW.recipient_role <=> OLD.recipient_role AND NEW.barangay <=> OLD.barangay AND NEW.fiscal_year <=> OLD.fiscal_year
          AND NEW.amount <=> OLD.amount AND NEW.message <=> OLD.message AND NEW.created_at <=> OLD.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: only read_at of a budget notification may change';
  END IF;
END $$
DROP TRIGGER IF EXISTS trg_bn_bd $$
CREATE TRIGGER trg_bn_bd BEFORE DELETE ON tbl_budget_notification FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: budget notifications cannot be deleted' $$

-- ------------------------------ tbl_procurement_upload ------------------------------
DROP TRIGGER IF EXISTS trg_pu_bi $$
CREATE TRIGGER trg_pu_bi BEFORE INSERT ON tbl_procurement_upload FOR EACH ROW
BEGIN
  DECLARE v_role VARCHAR(30);
  DECLARE v_status VARCHAR(20);
  DECLARE v_brgy VARCHAR(100);
  DECLARE v_budget DECIMAL(16,2);

  SELECT role, status, barangay INTO v_role, v_status, v_brgy FROM users WHERE id = NEW.submitted_by;
  IF v_role IS NULL OR v_role <> 'user' OR v_status <> 'approved' OR v_brgy <> NEW.barangay THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FORBIDDEN: only approved barangay staff may submit expenses, and only for their own barangay';
  END IF;

  SELECT COALESCE(SUM(amount), 0) INTO v_budget FROM tbl_budget_allocation WHERE barangay = NEW.barangay AND fiscal_year = NEW.fiscal_year;
  IF v_budget <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_BUDGET: no budget has been allocated to this barangay for that fiscal year';
  END IF;

  IF NEW.revision_of IS NOT NULL THEN
    IF NOT EXISTS (SELECT 1 FROM tbl_procurement_upload o WHERE o.id = NEW.revision_of AND o.barangay = NEW.barangay)
       OR NOT EXISTS (SELECT 1 FROM tbl_procurement_review r WHERE r.upload_id = NEW.revision_of AND r.decision = 'returned') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A revision may only replace a packet of the same barangay that an Admin returned';
    END IF;
  END IF;

  SET NEW.uploaded_at = NOW(6);
END $$
DROP TRIGGER IF EXISTS trg_pu_bu $$
CREATE TRIGGER trg_pu_bu BEFORE UPDATE ON tbl_procurement_upload FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: submitted expense packets cannot be modified - upload a revision instead' $$
DROP TRIGGER IF EXISTS trg_pu_bd $$
CREATE TRIGGER trg_pu_bd BEFORE DELETE ON tbl_procurement_upload FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: submitted expense packets cannot be deleted' $$

-- ------------------------------ tbl_ai_verification_log ------------------------------
DROP TRIGGER IF EXISTS trg_ai_bi $$
CREATE TRIGGER trg_ai_bi BEFORE INSERT ON tbl_ai_verification_log FOR EACH ROW
BEGIN
  DECLARE v_run  INT UNSIGNED;
  DECLARE v_prev CHAR(64);

  SELECT COALESCE(MAX(run_no), 0) + 1 INTO v_run FROM tbl_ai_verification_log WHERE upload_id = NEW.upload_id;
  SELECT row_hash INTO v_prev FROM tbl_ai_verification_log WHERE upload_id = NEW.upload_id ORDER BY run_no DESC LIMIT 1;
  SET NEW.run_no     = v_run;
  SET NEW.created_at = NOW(6);
  SET NEW.prev_hash  = COALESCE(v_prev, REPEAT('0', 64));
  SET NEW.row_hash   = SHA2(CONCAT_WS('|', NEW.prev_hash, NEW.upload_id, NEW.run_no, NEW.ai_provider, NEW.ai_model, NEW.engine_version,
                                      COALESCE(NEW.excel_total, ''), COALESCE(NEW.receipt_total, ''), NEW.allocated_budget, NEW.spent_before,
                                      NEW.remaining_after, NEW.over_budget, NEW.match_score, NEW.verdict,
                                      COALESCE(NEW.duplicate_of_upload_id, ''), NEW.response_sha256, NEW.created_at), 256);
END $$
DROP TRIGGER IF EXISTS trg_ai_bu $$
CREATE TRIGGER trg_ai_bu BEFORE UPDATE ON tbl_ai_verification_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: AI verification results cannot be modified' $$
DROP TRIGGER IF EXISTS trg_ai_bd $$
CREATE TRIGGER trg_ai_bd BEFORE DELETE ON tbl_ai_verification_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: AI verification results cannot be deleted' $$

DROP TRIGGER IF EXISTS trg_pli_bu $$
CREATE TRIGGER trg_pli_bu BEFORE UPDATE ON tbl_procurement_line_item FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: extracted line items cannot be modified' $$
DROP TRIGGER IF EXISTS trg_pli_bd $$
CREATE TRIGGER trg_pli_bd BEFORE DELETE ON tbl_procurement_line_item FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: extracted line items cannot be deleted' $$

-- ------------------------------ tbl_procurement_review ------------------------------
DROP TRIGGER IF EXISTS trg_pr_bi $$
CREATE TRIGGER trg_pr_bi BEFORE INSERT ON tbl_procurement_review FOR EACH ROW
BEGIN
  DECLARE v_role    VARCHAR(30);
  DECLARE v_status  VARCHAR(20);
  DECLARE v_latest  BIGINT UNSIGNED;
  DECLARE v_verdict VARCHAR(20);
  DECLARE v_dup     BIGINT UNSIGNED;
  DECLARE v_over    TINYINT(1);

  SELECT role, status INTO v_role, v_status FROM users WHERE id = NEW.reviewer_id;
  IF v_role IS NULL OR v_role NOT IN ('admin', 'official') OR v_status <> 'approved' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FORBIDDEN: only a DRRMC Admin may record a review decision';
  END IF;

  IF EXISTS (SELECT 1 FROM tbl_procurement_review r WHERE r.upload_id = NEW.upload_id AND r.decision IN ('approved', 'returned')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DECISION_FINAL: this packet was already approved or returned';
  END IF;

  -- the decision must rest on the newest AI proof of this packet
  SELECT MAX(id) INTO v_latest FROM tbl_ai_verification_log WHERE upload_id = NEW.upload_id;
  IF v_latest IS NULL OR v_latest <> NEW.ai_log_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NO_CURRENT_AI_PROOF: review is only possible against the latest AI verification of this packet';
  END IF;
  SELECT verdict, duplicate_of_upload_id, over_budget INTO v_verdict, v_dup, v_over FROM tbl_ai_verification_log WHERE id = NEW.ai_log_id;

  IF NEW.decision = 'approved' AND (v_verdict <> 'verified_match' OR v_dup IS NOT NULL OR v_over = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An expense can only be approved when the AI proof is a full match, not a duplicate and within budget';
  END IF;
  IF NEW.decision = 'verified' AND v_verdict NOT IN ('verified_match', 'partial_match') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A packet whose AI proof is a mismatch or unreadable can only be returned';
  END IF;
  IF NEW.decision IN ('verified', 'returned') AND CHAR_LENGTH(TRIM(COALESCE(NEW.remarks, ''))) < 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Remarks (10+ characters) are required when verifying with discrepancies or returning a packet';
  END IF;

  SET NEW.decided_at = NOW(6);
END $$
DROP TRIGGER IF EXISTS trg_pr_bu $$
CREATE TRIGGER trg_pr_bu BEFORE UPDATE ON tbl_procurement_review FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: review decisions cannot be modified' $$
DROP TRIGGER IF EXISTS trg_pr_bd $$
CREATE TRIGGER trg_pr_bd BEFORE DELETE ON tbl_procurement_review FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: review decisions cannot be deleted' $$

-- ------------------------------ OPTIONAL: make the existing audit log immutable too ------------------------------
-- Uncomment for production. (While developing you will not be able to delete test rows from tbl_audit_log.)
-- DROP TRIGGER IF EXISTS trg_audit_bu $$
-- CREATE TRIGGER trg_audit_bu BEFORE UPDATE ON tbl_audit_log FOR EACH ROW
--   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: audit log entries cannot be modified' $$
-- DROP TRIGGER IF EXISTS trg_audit_bd $$
-- CREATE TRIGGER trg_audit_bd BEFORE DELETE ON tbl_audit_log FOR EACH ROW
--   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE_RECORD: audit log entries cannot be deleted' $$

DELIMITER ;

-- =====================================================================================
--  VIEWS  (read-only reporting; what the dashboards read)
-- =====================================================================================

-- Current state of each expense packet: awaiting AI -> awaiting review -> approved / verified / returned
CREATE OR REPLACE VIEW v_procurement_status AS
SELECT u.id AS upload_id, u.barangay, u.fiscal_year, u.submitted_by, u.revision_of, u.uploaded_at,
       a.id AS ai_log_id, a.match_score, a.verdict, a.excel_total, a.receipt_total, a.over_budget, a.duplicate_of_upload_id,
       r.id AS review_id, r.decision, r.reviewer_id, r.decided_at,
       CASE WHEN a.id IS NULL THEN 'awaiting_ai'
            WHEN r.id IS NULL THEN 'awaiting_review'
            ELSE r.decision END AS status
  FROM tbl_procurement_upload u
  LEFT JOIN tbl_ai_verification_log a ON a.id = (SELECT MAX(x.id) FROM tbl_ai_verification_log x WHERE x.upload_id = u.id)
  LEFT JOIN tbl_procurement_review  r ON r.id = (SELECT MAX(y.id) FROM tbl_procurement_review y WHERE y.upload_id = u.id AND y.ai_log_id = a.id);

-- Budget position per barangay and year.  "Spent" counts only packets an Admin approved/verified, using the AI-read receipt total.
CREATE OR REPLACE VIEW v_budget_balance AS
SELECT b.barangay, b.fiscal_year, b.allocated,
       COALESCE(s.spent, 0)   AS spent,
       COALESCE(p.pending, 0) AS pending_review,
       b.allocated - COALESCE(s.spent, 0) AS remaining
  FROM (SELECT barangay, fiscal_year, SUM(amount) AS allocated FROM tbl_budget_allocation GROUP BY barangay, fiscal_year) b
  LEFT JOIN (SELECT barangay, fiscal_year, SUM(receipt_total) AS spent
               FROM v_procurement_status WHERE status IN ('approved', 'verified') GROUP BY barangay, fiscal_year) s
         ON s.barangay = b.barangay AND s.fiscal_year = b.fiscal_year
  LEFT JOIN (SELECT barangay, fiscal_year, SUM(receipt_total) AS pending
               FROM v_procurement_status WHERE status = 'awaiting_review' GROUP BY barangay, fiscal_year) p
         ON p.barangay = b.barangay AND p.fiscal_year = b.fiscal_year;

-- Tamper check for the ledger: every row must link to its predecessor and match its own hash.  Any 0 = tampering.
CREATE OR REPLACE VIEW v_budget_ledger_integrity AS
SELECT id, barangay, fiscal_year, seq,
       (prev_hash = COALESCE(LAG(row_hash) OVER w, REPEAT('0', 64))) AS link_ok,
       (row_hash = SHA2(CONCAT_WS('|', prev_hash, barangay, fiscal_year, seq, entry_type, amount, allocated_by,
                                  COALESCE(adjusts_id, ''), COALESCE(reason, ''), allocated_at), 256)) AS hash_ok
  FROM tbl_budget_allocation
WINDOW w AS (PARTITION BY barangay, fiscal_year ORDER BY seq);

-- Tamper check for AI proofs.
CREATE OR REPLACE VIEW v_ai_log_integrity AS
SELECT id, upload_id, run_no,
       (prev_hash = COALESCE(LAG(row_hash) OVER w, REPEAT('0', 64))) AS link_ok,
       (row_hash = SHA2(CONCAT_WS('|', prev_hash, upload_id, run_no, ai_provider, ai_model, engine_version,
                                  COALESCE(excel_total, ''), COALESCE(receipt_total, ''), allocated_budget, spent_before,
                                  remaining_after, over_budget, match_score, verdict,
                                  COALESCE(duplicate_of_upload_id, ''), response_sha256, created_at), 256)) AS hash_ok
  FROM tbl_ai_verification_log
WINDOW w AS (PARTITION BY upload_id ORDER BY run_no);

-- =====================================================================================
--  RECOMMENDED HARDENING (run by the database owner; adjust names).  The triggers above already block edits, but a
--  dedicated app account that simply has no UPDATE/DELETE right is a second lock:
--
--    GRANT SELECT, INSERT ON capstone_db.tbl_budget_allocation    TO 'drrmc_app'@'localhost';
--    GRANT SELECT, INSERT ON capstone_db.tbl_procurement_upload   TO 'drrmc_app'@'localhost';
--    GRANT SELECT, INSERT ON capstone_db.tbl_ai_verification_log  TO 'drrmc_app'@'localhost';
--    GRANT SELECT, INSERT ON capstone_db.tbl_procurement_line_item TO 'drrmc_app'@'localhost';
--    GRANT SELECT, INSERT ON capstone_db.tbl_procurement_review   TO 'drrmc_app'@'localhost';
--    GRANT SELECT, INSERT, UPDATE (read_at) ON capstone_db.tbl_budget_notification TO 'drrmc_app'@'localhost';
--    GRANT SELECT ON capstone_db.v_procurement_status, capstone_db.v_budget_balance TO 'drrmc_app'@'localhost';
--    (no TRUNCATE / DROP / ALTER / TRIGGER rights for the app account)
-- =====================================================================================
