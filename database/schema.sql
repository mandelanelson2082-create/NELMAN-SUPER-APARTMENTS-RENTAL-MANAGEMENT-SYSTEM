-- =====================================================================
--  Rental Management System - database schema
--  Database: rentals   Engine: InnoDB / utf8mb4
--  Load with:  mysql -u root < database/schema.sql
--
--  Enterprise edition: adds multi-property buildings, custom invoices,
--  a key/value settings store (Pesapal config) and must-change-password.
--  Existing installs: run database/upgrade.sql instead.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS rentals
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rentals;

-- ------------------------------ admins ------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  username   VARCHAR(60)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,           -- password_hash() output
  role       ENUM('ADMIN','MANAGER') NOT NULL DEFAULT 'ADMIN',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------- properties ----------------------------
-- A building / property that groups house units (multi-property support).
CREATE TABLE IF NOT EXISTS properties (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  location   VARCHAR(200) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------ houses ------------------------------
CREATE TABLE IF NOT EXISTS houses (
  id          VARCHAR(50) PRIMARY KEY,         -- manual ids: H1, A101, BLOCK-A-01
  property_id INT NULL, 
  property_name VARCHAR(150) NOT NULL,             -- parent building
  rent_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status      ENUM('VACANT','OCCUPIED') NOT NULL DEFAULT 'VACANT',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_house_property FOREIGN KEY (property_id) REFERENCES properties(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------- tenants ------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(120) NOT NULL,
  phone                VARCHAR(30)  NOT NULL UNIQUE,
  email                VARCHAR(150) NULL UNIQUE,
  email_verified_at    DATETIME NULL,
  activation_token_hash CHAR(64) NULL,
  activation_token_expires_at DATETIME NULL,
  password             VARCHAR(255) NOT NULL,           -- password_hash() output
  house_id             VARCHAR(50)  NULL,
  rent_amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  status               ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = provisioned, force change on login
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenant_activation_token (activation_token_hash),
  CONSTRAINT fk_tenant_house FOREIGN KEY (house_id) REFERENCES houses(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------- invoices -----------------------------
-- RENT invoices carry month/year and are unique per tenant/period.
-- CUSTOM invoices (water, maintenance, security...) leave month/year NULL,
-- which the UNIQUE index treats as distinct, so many custom bills can coexist.
CREATE TABLE IF NOT EXISTS invoices (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT NOT NULL,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  type           ENUM('RENT','CUSTOM') NOT NULL DEFAULT 'RENT',
  description    VARCHAR(200) NULL,
  month          TINYINT NULL,                 -- 1..12 (RENT only)
  year           SMALLINT NULL,                -- e.g. 2026 (RENT only)
  amount         DECIMAL(12,2) NOT NULL,
  amount_paid    DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_date       DATE NULL,
  status         ENUM('UNPAID','PARTIAL','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
  email_sent_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_invoice_period UNIQUE (tenant_id, month, year),  -- prevents duplicate rent invoices
  CONSTRAINT fk_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE,
  INDEX idx_invoice_status (status)
) ENGINE=InnoDB;

-- ----------------------------- payments -----------------------------
CREATE TABLE IF NOT EXISTS payments (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id         INT NOT NULL,
  invoice_id        INT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  currency          VARCHAR(3) NOT NULL DEFAULT 'KES',
  merchant_reference VARCHAR(60) NOT NULL UNIQUE,
  order_tracking_id VARCHAR(80) NULL,
  payment_method    VARCHAR(40) NULL,
  confirmation_code VARCHAR(80) NULL,
  payment_account   VARCHAR(120) NULL,
  status            ENUM('PENDING','COMPLETED','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  transaction_date  DATETIME NULL,
  raw_response      TEXT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id)  ON DELETE CASCADE,
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  INDEX idx_payment_status (status),
  INDEX idx_payment_tracking (order_tracking_id)
) ENGINE=InnoDB;

-- ------------------------- payment allocations -----------------------
-- Completed advance payments are allocated to invoices over time. Any unallocated
-- amount remains tenant credit and is automatically applied to later invoices.
CREATE TABLE IF NOT EXISTS payment_allocations (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  payment_id  INT NOT NULL,
  invoice_id  INT NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_allocation_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  UNIQUE KEY uq_payment_invoice_allocation (payment_id, invoice_id),
  INDEX idx_allocation_invoice (invoice_id)
) ENGINE=InnoDB;

-- ----------------------------- settings -----------------------------
-- Simple key/value store for runtime-configurable options (Pesapal gateway).
CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(60) PRIMARY KEY,
  svalue     TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
