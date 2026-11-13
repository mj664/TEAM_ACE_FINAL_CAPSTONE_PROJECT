-- Release & Obtained (check-out / check-in) audit log.
-- The application runs this automatically (item_tx_lib.php: item_tx_install()); this file is for documentation
-- or for setting up a fresh database by hand. It only ADDS a table; no existing table is changed.

CREATE TABLE IF NOT EXISTS item_transactions (
    id                INT AUTO_INCREMENT PRIMARY KEY,           -- shown as TXN-YYYYMMDD-00001
    txn_type          ENUM('released','obtained') NOT NULL,     -- Released / Check-out, Obtained / Check-in
    report_id         INT NOT NULL,                             -- the equipment report (Main Inventory) that was updated
    barangay          VARCHAR(10) NOT NULL,
    item_key          VARCHAR(40) NOT NULL,                     -- column of equipment_reports, e.g. 'gloves'
    item_name         VARCHAR(100) NOT NULL,
    category          VARCHAR(40) NOT NULL,                     -- Tools & Equipment / Medical Supplies
    quantity          INT UNSIGNED NOT NULL,
    qty_before        INT NOT NULL,
    qty_after         INT NOT NULL,
    processed_by      INT NOT NULL,                             -- users.id of the staff who recorded it
    processed_by_name VARCHAR(255) NOT NULL,
    counterpart       VARCHAR(150) NULL,                        -- released to / borrower, or obtained from
    remarks           VARCHAR(500) NULL,                        -- remarks / purpose
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tx_brgy_date (barangay, created_at),
    INDEX idx_tx_item (item_key),
    INDEX idx_tx_date (created_at),
    CONSTRAINT fk_tx_report FOREIGN KEY (report_id) REFERENCES equipment_reports(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tx_user   FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_tx_qty   CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only: records can never be edited or deleted.
DELIMITER $$
CREATE TRIGGER trg_item_tx_no_update BEFORE UPDATE ON item_transactions FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_transactions is an audit log: records cannot be changed or deleted'$$
CREATE TRIGGER trg_item_tx_no_delete BEFORE DELETE ON item_transactions FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_transactions is an audit log: records cannot be changed or deleted'$$
DELIMITER ;

-- How a transaction is saved (item_tx_record() in item_tx_lib.php), all inside ONE database transaction:
--   START TRANSACTION;
--   SELECT ... FROM equipment_reports (latest report of the barangay) ... FOR UPDATE;   -- lock the stock row
--   -- release: stop if quantity > stock on hand
--   UPDATE equipment_reports SET <item> = <new quantity> WHERE id = <report_id>;
--   INSERT INTO item_transactions (...) VALUES (...);
--   COMMIT;            -- or ROLLBACK if any step fails: stock and log always match
