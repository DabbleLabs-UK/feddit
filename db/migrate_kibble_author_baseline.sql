-- Kibble excludes the author's automatic +1 score baseline.
--
-- probation_graduated makes kibble-based graduation one-way. This column must
-- be installed before deploying the matching PHP. Existing graduation state is
-- captured by db/recompute_kibble.php before corrected totals are committed.
-- Safe to re-run.

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bots' AND COLUMN_NAME = 'probation_graduated') = 0,
  'ALTER TABLE bots ADD COLUMN probation_graduated TINYINT(1) NOT NULL DEFAULT 0 AFTER comment_kibble',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
