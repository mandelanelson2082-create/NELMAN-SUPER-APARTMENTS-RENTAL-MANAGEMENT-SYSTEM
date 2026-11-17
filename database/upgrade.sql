-- =====================================================================
--  Upgrade script: brings an EXISTING rentals database (base edition)
--  up to the enterprise edition. Safe to re-run? NO -- run once.
--  For a brand-new install, use schema.sql instead.
--
--  mysql -u root rentals < database/upgrade.sql
-- =====================================================================
USE rentals;

-- 1) properties table (multi-property buildings)
CREATE TABLE IF NOT EXISTS properties (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  location   VARCHAR(200) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2) houses.property_id
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='houses' AND column_name='property_id');
SET @sql := IF(@col=0,
  'ALTER TABLE houses ADD COLUMN property_id INT NULL AFTER id,
   ADD CONSTRAINT fk_house_property FOREIGN KEY (property_id) REFERENCES properties(id)
     ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) tenants.must_change_password
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='tenants' AND column_name='must_change_password');
SET @sql := IF(@col=0,
  'ALTER TABLE tenants ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) invoices.type + description, and relax month/year to allow custom bills
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='invoices' AND column_name='type');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN type ENUM(''RENT'',''CUSTOM'') NOT NULL DEFAULT ''RENT'' AFTER invoice_number,
   ADD COLUMN description VARCHAR(200) NULL AFTER type',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='invoices'
               AND column_name='month' AND is_nullable='NO');
SET @sql := IF(@col>0,
  'ALTER TABLE invoices MODIFY month TINYINT NULL, MODIFY year SMALLINT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) settings table (Pesapal runtime config)
CREATE TABLE IF NOT EXISTS settings (
  skey       VARCHAR(60) PRIMARY KEY,
  svalue     TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6) backfill: a default property, and rent descriptions for existing invoices
INSERT INTO properties (id, name, location)
  SELECT 1, 'Default Property', '—'
  WHERE NOT EXISTS (SELECT 1 FROM properties WHERE id = 1);

UPDATE houses SET property_id = 1 WHERE property_id IS NULL;

UPDATE invoices SET type='RENT',
  description = CONCAT('Rent ', MONTHNAME(STR_TO_DATE(CONCAT(year,'-',month,'-01'),'%Y-%m-%d')), ' ', year)
  WHERE type='RENT' AND description IS NULL AND month IS NOT NULL;

-- 7) payment allocations for advance rent credit
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
