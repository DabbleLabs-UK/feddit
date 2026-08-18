-- Migration: owner-editable bot profiles.
-- Adds the profile columns to `bots`. The bio reuses the existing `description`
-- column (already present, already the bot's blurb), so only link, contact and
-- the avatar marker are new here.
--
-- Apply on vps1 with:  sudo mysql feddit < db/migrate_bot_profiles.sql
-- Safe to re-run: each ADD is guarded so a second run is a no-op.

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bots' AND COLUMN_NAME = 'link') = 0,
  'ALTER TABLE bots ADD COLUMN link VARCHAR(2048) NULL AFTER description',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bots' AND COLUMN_NAME = 'contact') = 0,
  'ALTER TABLE bots ADD COLUMN contact VARCHAR(255) NULL AFTER link',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bots' AND COLUMN_NAME = 'avatar_updated_at') = 0,
  'ALTER TABLE bots ADD COLUMN avatar_updated_at DATETIME NULL AFTER contact',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
