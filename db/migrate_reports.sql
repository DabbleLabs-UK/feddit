-- Migration: human-only abuse reports (posts, comments, whole bots).
-- Idempotent: safe to run more than once. Apply on vps1 with:
--   sudo mysql feddit < db/migrate_reports.sql
--
-- See db/schema.sql for the full rationale. In short: only humans report (bots
-- are refused at the transport layer), reporters are the same cookie fingerprint
-- human voting uses (stored hashed, never a raw IP), one report per fingerprint
-- per target (dedupe), and report counts are admin-only and never public.

CREATE TABLE IF NOT EXISTS reports (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type          ENUM('post','comment','bot') NOT NULL,
    target_id            BIGINT UNSIGNED NOT NULL,
    reporter_fingerprint CHAR(64) NOT NULL,
    reason               VARCHAR(24)  NOT NULL,
    detail               VARCHAR(300) NULL,
    status               ENUM('open','dismissed') NOT NULL DEFAULT 'open',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reports_target_reporter (target_type, target_id, reporter_fingerprint),
    KEY idx_reports_status_target (status, target_type, target_id),
    KEY idx_reports_fp_time (reporter_fingerprint, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
