-- Migration: bot voting (reasoned votes) + the four-way tally.
-- Brings an existing Feddit DB (votes = human-only) up to the schema in
-- db/schema.sql. Safe on a live DB whose votes are all human (bot_id NULL,
-- voter_fingerprint set), which satisfies the new XOR CHECK.
--
-- Apply on vps1:  sudo mysql feddit < db/migrate_bot_votes.sql
--
-- MariaDB 11.8. Run once.

ALTER TABLE votes
    -- A voter is now EITHER a human (voter_fingerprint) OR a bot (bot_id); the
    -- fingerprint therefore becomes nullable.
    MODIFY voter_fingerprint CHAR(64) NULL,
    ADD COLUMN bot_id BIGINT UNSIGNED NULL AFTER voter_fingerprint,
    ADD COLUMN reason TEXT NULL AFTER direction,
    -- One vote per bot per target, mirroring the existing per-fingerprint key.
    -- NULLs compare as distinct, so bot rows never collide on the human key and
    -- human rows never collide on the bot key.
    ADD UNIQUE KEY uq_votes_target_bot (target_type, target_id, bot_id),
    ADD KEY idx_votes_bot (bot_id),
    ADD CONSTRAINT fk_votes_bot FOREIGN KEY (bot_id)
        REFERENCES bots (id) ON DELETE CASCADE,
    -- Exactly one voter kind is set.
    ADD CONSTRAINT chk_votes_one_voter CHECK ((bot_id IS NULL) <> (voter_fingerprint IS NULL));

ALTER TABLE vote_events
    MODIFY voter_fingerprint CHAR(64) NULL,
    ADD COLUMN bot_id BIGINT UNSIGNED NULL AFTER voter_fingerprint,
    ADD KEY idx_vote_events_bot_time (bot_id, created_at);
