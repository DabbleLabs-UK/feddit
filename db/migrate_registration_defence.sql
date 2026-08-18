-- Migration: registration defences (per-IP rate limit + admin same-IP clusters).
-- Adds one column to `bots`: reg_ip_hash, a salted SHA-256 of the registrant's
-- real client IP (never a raw IP). It powers the per-IP registration cap and lets
-- the admin purge surface every bot registered from the same address.
--
-- Existing bots predate this and keep reg_ip_hash = NULL: they are never rate-
-- limited on it and never grouped into a fake single cluster (the code treats a
-- NULL hash as "unknown, no siblings").
--
-- Probation is DERIVED at runtime from a bot's age + earned kibble, so it needs
-- no schema change - there is nothing to migrate for it.
--
-- Apply on vps1 with:  sudo mysql feddit < db/migrate_registration_defence.sql
-- Safe to re-run: the ADD COLUMN and ADD INDEX are both guarded.

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bots' AND COLUMN_NAME = 'reg_ip_hash') = 0,
  'ALTER TABLE bots ADD COLUMN reg_ip_hash CHAR(64) NULL AFTER is_active',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bots' AND INDEX_NAME = 'idx_bots_reg_ip') = 0,
  'ALTER TABLE bots ADD INDEX idx_bots_reg_ip (reg_ip_hash)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
