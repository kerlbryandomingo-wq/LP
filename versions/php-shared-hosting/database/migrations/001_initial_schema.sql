-- LP System — PHP/MySQL shared-hosting edition
-- Initial schema, ported from the original Next.js app's prisma/schema.prisma.
-- MySQL/MariaDB, InnoDB, utf8mb4. Monetary columns use DECIMAL(12,2) — never
-- FLOAT/DOUBLE — matching the original @db.Decimal(12,2) exactly.
--
-- Scope note (see FEATURE-PARITY.md for the full list): this initial
-- schema covers the core transactional workflow (auth, customers,
-- inquiries, quotations, orders, payments, job orders/production, QC,
-- inventory, business settings, audit log). Modules intentionally not
-- yet ported (Messenger integration, OAuth, customer-form versioning/
-- locking, reward points, SOA/statements, expense tracking) are
-- documented as deferred rather than silently dropped.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- Users & roles (prisma: enum Role { ADMIN STAFF PRODUCTION CUSTOMER })
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(30) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NULL,
    role ENUM('ADMIN','STAFF','PRODUCTION','CUSTOMER') NOT NULL,
    phone VARCHAR(50) NULL,
    title VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_active_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Customers (prisma: model Customer)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id VARCHAR(30) PRIMARY KEY,
    display_id VARCHAR(30) NOT NULL,
    user_id VARCHAR(30) NULL,
    name VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    contact_number VARCHAR(50) NULL,
    address TEXT NULL,
    facebook_url VARCHAR(500) NULL,
    profile_image_url VARCHAR(500) NULL,
    is_qualified_for_terms TINYINT(1) NOT NULL DEFAULT 0,
    reward_points_balance INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_display_id (display_id),
    UNIQUE KEY uq_customers_user_id (user_id),
    CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Services (minimal catalog — prisma: model Service, reduced to the
-- fields this slice's quotation/order line items need)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id VARCHAR(30) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    production_cost DECIMAL(12,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_services_name (name),
    KEY idx_services_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inquiries (prisma: model Inquiry, enum InquiryStatus)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inquiries (
    id VARCHAR(30) PRIMARY KEY,
    customer_id VARCHAR(30) NOT NULL,
    description TEXT NOT NULL,
    desired_product VARCHAR(255) NOT NULL,
    service_id VARCHAR(30) NULL,
    specs JSON NULL,
    rough_qty INT NULL,
    rough_qty_unit VARCHAR(50) NULL,
    status ENUM('NEW','QUOTED','CLOSED','CANCELLED') NOT NULL DEFAULT 'NEW',
    cancelled_at DATETIME NULL,
    cancelled_by_id VARCHAR(30) NULL,
    cancel_reason TEXT NULL,
    status_before_cancel ENUM('NEW','QUOTED','CLOSED','CANCELLED') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inquiries_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_inquiries_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_inquiries_cancelled_by FOREIGN KEY (cancelled_by_id) REFERENCES users(id),
    KEY idx_inquiries_status (status),
    KEY idx_inquiries_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Quotations (prisma: model Quotation / QuotationLineItem)
-- Discount/tax breakdown preserved exactly — see lib/pricing-totals.ts's
-- computeTotals(), ported verbatim in app/Services/PricingService.php.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotations (
    id VARCHAR(30) PRIMARY KEY,
    inquiry_id VARCHAR(30) NULL,
    customer_id VARCHAR(30) NOT NULL,
    quote_number VARCHAR(50) NOT NULL,
    status ENUM('DRAFT','SENT','APPROVED','REJECTED','REVISION_REQUESTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    valid_until DATE NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_type ENUM('PERCENTAGE','FIXED') NOT NULL DEFAULT 'PERCENTAGE',
    discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_label VARCHAR(100) NULL,
    tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_instant TINYINT(1) NOT NULL DEFAULT 0,
    reject_reason TEXT NULL,
    notes TEXT NULL,
    created_by_id VARCHAR(30) NULL,
    cancelled_at DATETIME NULL,
    cancelled_by_id VARCHAR(30) NULL,
    cancel_reason TEXT NULL,
    status_before_cancel ENUM('DRAFT','SENT','APPROVED','REJECTED','REVISION_REQUESTED','CANCELLED') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quotations_number (quote_number),
    CONSTRAINT fk_quotations_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries(id),
    CONSTRAINT fk_quotations_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_quotations_created_by FOREIGN KEY (created_by_id) REFERENCES users(id),
    KEY idx_quotations_status (status),
    KEY idx_quotations_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotation_line_items (
    id VARCHAR(30) PRIMARY KEY,
    quotation_id VARCHAR(30) NOT NULL,
    product_type VARCHAR(255) NOT NULL,
    service_id VARCHAR(30) NULL,
    specs JSON NULL,
    description TEXT NOT NULL,
    qty INT NOT NULL,
    unit VARCHAR(50) NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    CONSTRAINT fk_qli_quotation FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    CONSTRAINT fk_qli_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Orders (prisma: model Order / OrderLineItem)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(30) PRIMARY KEY,
    quotation_id VARCHAR(30) NULL,
    customer_id VARCHAR(30) NOT NULL,
    order_number VARCHAR(50) NOT NULL,
    status ENUM('OPEN','IN_PRODUCTION','FULFILLING','COMPLETED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    payment_term_type ENUM('STANDARD_PARTIAL','APPROVED_TERMS') NOT NULL DEFAULT 'STANDARD_PARTIAL',
    terms_approved_by VARCHAR(255) NULL,
    terms_reason TEXT NULL,
    required_partial_pct INT NOT NULL DEFAULT 50,
    subtotal DECIMAL(12,2) NULL,
    discount_type ENUM('PERCENTAGE','FIXED') NOT NULL DEFAULT 'PERCENTAGE',
    discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_label VARCHAR(100) NULL,
    tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    release_exception TINYINT(1) NOT NULL DEFAULT 0,
    release_exception_by VARCHAR(255) NULL,
    release_exception_reason TEXT NULL,
    due_date DATE NULL,
    notes TEXT NULL,
    is_historical TINYINT(1) NOT NULL DEFAULT 0,
    historical_order_type ENUM('PENDING_PRODUCTION','ALREADY_RELEASED') NULL,
    historical_notes TEXT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by_id VARCHAR(30) NULL,
    cancel_reason TEXT NULL,
    status_before_cancel ENUM('OPEN','IN_PRODUCTION','FULFILLING','COMPLETED','CANCELLED') NULL,
    completed_at DATETIME NULL,
    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orders_number (order_number),
    CONSTRAINT fk_orders_quotation FOREIGN KEY (quotation_id) REFERENCES quotations(id),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_orders_cancelled_by FOREIGN KEY (cancelled_by_id) REFERENCES users(id),
    KEY idx_orders_status (status),
    KEY idx_orders_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_line_items (
    id VARCHAR(30) PRIMARY KEY,
    order_id VARCHAR(30) NOT NULL,
    product_type VARCHAR(255) NOT NULL,
    service_id VARCHAR(30) NULL,
    specs JSON NULL,
    description TEXT NOT NULL,
    qty INT NOT NULL,
    unit VARCHAR(50) NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    CONSTRAINT fk_oli_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_oli_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Payments (prisma: model Payment, enum PaymentStatus/PaymentMethod)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(30) PRIMARY KEY,
    order_id VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('CASH','BANK_TRANSFER','GCASH','MAYA','CHEQUE','VOUCHER','OTHER') NOT NULL,
    proof_file_path VARCHAR(500) NULL,
    status ENUM('PENDING','CONFIRMED','REJECTED') NOT NULL DEFAULT 'PENDING',
    reference_number VARCHAR(100) NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by_id VARCHAR(30) NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES users(id),
    KEY idx_payments_order (order_id),
    KEY idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Production — workflow templates/stages + job orders
-- (prisma: WorkflowTemplate / WorkflowStage / JobOrder)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workflow_templates (
    id VARCHAR(30) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workflow_stages (
    id VARCHAR(30) PRIMARY KEY,
    template_id VARCHAR(30) NOT NULL,
    name VARCHAR(255) NOT NULL,
    stage_order INT NOT NULL,
    is_qc_stage TINYINT(1) NOT NULL DEFAULT 0,
    is_install_stage TINYINT(1) NOT NULL DEFAULT 0,
    is_design_stage TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_stage_template_order (template_id, stage_order),
    CONSTRAINT fk_stages_template FOREIGN KEY (template_id) REFERENCES workflow_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_orders (
    id VARCHAR(30) PRIMARY KEY,
    order_id VARCHAR(30) NOT NULL,
    jo_number VARCHAR(50) NOT NULL,
    product_type VARCHAR(255) NOT NULL,
    service_id VARCHAR(30) NULL,
    specs JSON NULL,
    description TEXT NOT NULL,
    quantity INT NOT NULL,
    workflow_template_id VARCHAR(30) NOT NULL,
    current_stage_order INT NOT NULL DEFAULT 1,
    status ENUM('ON_HOLD','IN_PROGRESS','QC','REWORK','READY','RELEASED','COMPLETED') NOT NULL DEFAULT 'ON_HOLD',
    priority ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
    deadline DATETIME NULL,
    production_instructions TEXT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_orders_number (jo_number),
    CONSTRAINT fk_jo_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_jo_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_jo_template FOREIGN KEY (workflow_template_id) REFERENCES workflow_templates(id),
    KEY idx_job_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stage-change history — powers "Undo" (spec section 10 Production:
-- "Undo functionality"): each advance is logged so the immediately
-- prior stage/status can be restored exactly, not merely decremented.
CREATE TABLE IF NOT EXISTS job_order_stage_logs (
    id VARCHAR(30) PRIMARY KEY,
    job_order_id VARCHAR(30) NOT NULL,
    from_stage_order INT NULL,
    to_stage_order INT NOT NULL,
    from_status ENUM('ON_HOLD','IN_PROGRESS','QC','REWORK','READY','RELEASED','COMPLETED') NULL,
    to_status ENUM('ON_HOLD','IN_PROGRESS','QC','REWORK','READY','RELEASED','COMPLETED') NOT NULL,
    actor_id VARCHAR(30) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_jol_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_jol_actor FOREIGN KEY (actor_id) REFERENCES users(id),
    KEY idx_jol_job_order (job_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- QC (prisma: model QCResult, enum QCOutcome)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS qc_results (
    id VARCHAR(30) PRIMARY KEY,
    job_order_id VARCHAR(30) NOT NULL,
    item_label VARCHAR(255) NOT NULL,
    checked TINYINT(1) NOT NULL DEFAULT 0,
    outcome ENUM('PASS','FAIL') NULL,
    notes TEXT NULL,
    checked_by_id VARCHAR(30) NULL,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qc_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_qc_checked_by FOREIGN KEY (checked_by_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inventory (prisma: model InventoryItem, reduced)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_items (
    id VARCHAR(30) PRIMARY KEY,
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    reorder_threshold INT NOT NULL DEFAULT 0,
    current_qty INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Business settings — single row, same shape as prisma's BusinessSettings
-- (reduced to this slice's needs; id is always 'default').
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_settings (
    id VARCHAR(30) PRIMARY KEY DEFAULT 'default',
    business_name VARCHAR(255) NOT NULL DEFAULT 'LP System',
    tagline VARCHAR(255) NULL,
    logo_path VARCHAR(500) NULL,
    active_theme VARCHAR(50) NOT NULL DEFAULT '2026',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Audit log (prisma: model AuditLog, reduced) — every sensitive action
-- (login, payment confirm/reject, order cancel, role changes) writes
-- one row here; never silently skipped (spec section 15).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id VARCHAR(30) PRIMARY KEY,
    actor_id VARCHAR(30) NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(30) NOT NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id),
    KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Uploaded files metadata (payment proofs, etc.) — the actual bytes live
-- in storage/private-uploads/ (outside the public document root), never
-- served by a static path; app/Controllers/UploadController.php streams
-- them only after an authorization check. See SECURITY.md.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploaded_files (
    id VARCHAR(30) PRIMARY KEY,
    owner_type VARCHAR(50) NOT NULL,
    owner_id VARCHAR(30) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL,
    uploaded_by_id VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_uploaded_by FOREIGN KEY (uploaded_by_id) REFERENCES users(id),
    KEY idx_files_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
