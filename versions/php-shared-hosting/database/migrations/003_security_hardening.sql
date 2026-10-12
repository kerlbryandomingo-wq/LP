-- Release 3 (QA hardening).
--
-- login_attempts: failed sign-ins, stored server-side so throttling can't be
-- bypassed by discarding the session cookie (the previous session-only
-- counter could be, see QA report finding #4).
--
-- form_submissions: one row per submitted create-form token. Inserted in the
-- same transaction as the record it creates, so a double-click / resubmit of
-- the same form can't create a second payment, quotation, order, etc.
--
-- Column/index additions below are re-runnable: the Migrator skips an ALTER
-- whose column/index already exists (error 1060/1061), so an update that
-- was interrupted half-way can simply be applied again.

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email (email, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS form_submissions (
    token CHAR(64) NOT NULL PRIMARY KEY,
    user_id VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_form_submissions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Line items keep the order they were entered in.
ALTER TABLE quotation_line_items ADD COLUMN sort_order INT NOT NULL DEFAULT 0;
ALTER TABLE order_line_items ADD COLUMN sort_order INT NOT NULL DEFAULT 0;

-- Staff approval on the customer's behalf is recorded with who and why
-- (original app: approvedByStaffId / approvalBypassReason).
ALTER TABLE quotations ADD COLUMN approved_by_id VARCHAR(30) NULL;
ALTER TABLE quotations ADD COLUMN approval_reason TEXT NULL;
ALTER TABLE quotations ADD COLUMN approved_at DATETIME NULL;

-- One order per quotation, enforced by the database as well as the code
-- (QA finding: a double-submitted "Convert to Order" created two orders).
-- NULL quotation_id (orders not created from a quotation) is unaffected.
ALTER TABLE orders ADD UNIQUE KEY uq_orders_quotation (quotation_id);

-- One job order per order line item.
ALTER TABLE job_orders ADD COLUMN order_line_item_id VARCHAR(30) NULL;
ALTER TABLE job_orders ADD UNIQUE KEY uq_job_orders_line_item (order_line_item_id);

-- Payment confirmation/rejection: who and when.
ALTER TABLE payments ADD COLUMN reviewed_by_id VARCHAR(30) NULL;
ALTER TABLE payments ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE payments ADD COLUMN reject_reason TEXT NULL;

-- Inventory: track edits.
ALTER TABLE inventory_items ADD COLUMN updated_at DATETIME NULL;

-- Insertion order for QC results and stage-change logs. Two events in the
-- same second (a FAIL and its passing re-check, or two quick stage changes)
-- must still be ordered exactly: the QC gate reads the latest result per
-- item and Undo reverts the latest stage change.
ALTER TABLE qc_results ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE;
ALTER TABLE job_order_stage_logs ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE;
