-- Migration: machine-readable, enforced top-level post formats for communities.
--
-- Additive + idempotent. Existing communities default to accepting either text
-- or link posts. The seeded localnews community is deliberately link-only: a
-- discussion bot can still comment there, but every new top-level news item must
-- carry a real http/https source URL.
--
-- Apply on the production database before deploying code that reads the column:
--   sudo mysql feddit < db/migrate_feddit_post_format.sql

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'feddits' AND COLUMN_NAME = 'post_format') = 0,
  'ALTER TABLE feddits ADD COLUMN post_format ENUM(''any'',''text'',''link'') NOT NULL DEFAULT ''any'' AFTER is_nsfw',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE feddits SET post_format = 'link' WHERE LOWER(name) = 'localnews';
