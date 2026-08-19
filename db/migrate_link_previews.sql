-- Migration: link-post previews (og:image thumbnails + open-graph metadata).
--
-- Adds the preview columns to `posts`. These are populated OUT OF BAND by the
-- cron worker (db/og_worker.php), never in the submit request - a post renders
-- with the plain LINK fallback box until its metadata arrives. The worker fetches
-- ONLY the target's <head> (never article body text), so paywalled pages are
-- handled ethically by construction.
--
--   thumbnail_url  - served path of the LOCALLY cached, re-encoded thumbnail
--                    (e.g. /thumb/123.png). We cache + strip metadata rather than
--                    hotlink, so a visitor's IP never leaks to the publisher and a
--                    rotated remote URL never breaks the image. NULL until cached.
--   og_title       - og:title / twitter:title / <title> (metadata only)
--   og_description - og:description / twitter:description
--   og_site_name   - og:site_name
--   og_fetched_at  - timestamp of the LAST fetch attempt (drives freshness + the
--                    retry backoff, not just success).
--   og_status      - fetch lifecycle: 'pending' (queued), 'ok' (image cached),
--                    'no_image' (metadata fetched, no usable image), 'failed'
--                    (retryable error), 'blocked' (SSRF/robots refusal, terminal),
--                    'skipped' (not a fetchable link). NULL on text posts.
--   og_attempts    - attempt counter so the worker backs off and gives up rather
--                    than retrying a dead publisher forever.
--
-- Apply on vps1 with:  sudo mysql feddit < db/migrate_link_previews.sql
-- Safe to re-run: each ADD is guarded so a second run is a no-op. ADDITIVE only -
-- never DROPs or rewrites existing data.

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'thumbnail_url') = 0,
  'ALTER TABLE posts ADD COLUMN thumbnail_url VARCHAR(255) NULL AFTER url',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'og_title') = 0,
  'ALTER TABLE posts ADD COLUMN og_title VARCHAR(512) NULL AFTER thumbnail_url',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'og_description') = 0,
  'ALTER TABLE posts ADD COLUMN og_description VARCHAR(1024) NULL AFTER og_title',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'og_site_name') = 0,
  'ALTER TABLE posts ADD COLUMN og_site_name VARCHAR(255) NULL AFTER og_description',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'og_fetched_at') = 0,
  'ALTER TABLE posts ADD COLUMN og_fetched_at DATETIME NULL AFTER og_site_name',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'og_status') = 0,
  "ALTER TABLE posts ADD COLUMN og_status VARCHAR(16) NULL AFTER og_fetched_at",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'og_attempts') = 0,
  'ALTER TABLE posts ADD COLUMN og_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER og_status',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index the worker's claim query: it scans for link posts still needing a fetch.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_posts_og_status') = 0,
  'ALTER TABLE posts ADD INDEX idx_posts_og_status (og_status)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Queue every existing link post that has no preview state yet. New link posts
-- are queued at submit time; this one-off catches the rows that predate the
-- feature. Text posts are left with a NULL status (the worker only touches links).
UPDATE posts
   SET og_status = 'pending'
 WHERE kind = 'link' AND url IS NOT NULL AND og_status IS NULL;
