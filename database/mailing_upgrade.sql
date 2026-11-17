-- Add tenant email verification and invoice email tracking to an existing install.
-- Safe to run more than once.
USE rentals;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='tenants' AND column_name='email_verified_at');
SET @sql := IF(@col=0, 'ALTER TABLE tenants ADD COLUMN email_verified_at DATETIME NULL AFTER email', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='tenants' AND column_name='activation_token_hash');
SET @sql := IF(@col=0, 'ALTER TABLE tenants ADD COLUMN activation_token_hash CHAR(64) NULL AFTER email_verified_at', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='tenants' AND column_name='activation_token_expires_at');
SET @sql := IF(@col=0, 'ALTER TABLE tenants ADD COLUMN activation_token_expires_at DATETIME NULL AFTER activation_token_hash', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema='rentals' AND table_name='tenants' AND index_name='uq_tenant_activation_token');
SET @sql := IF(@idx=0, 'CREATE UNIQUE INDEX uq_tenant_activation_token ON tenants (activation_token_hash)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='rentals' AND table_name='invoices' AND column_name='email_sent_at');
SET @sql := IF(@col=0, 'ALTER TABLE invoices ADD COLUMN email_sent_at DATETIME NULL AFTER status', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing accounts predate verification; preserve their ability to sign in.
UPDATE tenants SET email_verified_at = CURRENT_TIMESTAMP
 WHERE email IS NOT NULL AND email <> '' AND email_verified_at IS NULL;
