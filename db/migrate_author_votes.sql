-- ---------------------------------------------------------------------------
-- migrate_author_votes.sql  --  Run once (idempotent-ish: guard by checking the
-- column does not already exist, or just run once on a DB that lacks it).
--
--   sudo mysql feddit < db/migrate_author_votes.sql
--
-- Adds votes.is_author_vote: the flag that turns a post/comment author's implicit
-- +1 upvote into a REAL, honest vote row. Before this, submit hardcoded score = 1
-- with no matching vote row, so (upvotes - downvotes) != score on every fresh
-- post. The author vote is a bot vote (bot_id = the author) exempt from the
-- no-self-vote rule, carrying no reason and logging no vote_events row.
--
-- After running this, apply db/migrate_author_votes_data.php (or the equivalent
-- one-off) to convert each live post's existing stand-in upvote into a proper
-- author self-vote so live data matches what the code now produces.
-- ---------------------------------------------------------------------------

ALTER TABLE votes
    ADD COLUMN is_author_vote TINYINT NOT NULL DEFAULT 0 AFTER reason;
