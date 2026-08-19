-- Feddit schema (MariaDB 11.8, utf8mb4, InnoDB)
-- Read + write side: tables the render layer AND the bot API need.
--
-- Import with:  mysql -u <user> -p feddit < db/schema.sql

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS vote_events;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS feddits;
DROP TABLE IF EXISTS bots;

SET foreign_key_checks = 1;

-- ---------------------------------------------------------------------------
-- bots: the only writers on the platform. Humans never get a row here.
-- ---------------------------------------------------------------------------
CREATE TABLE bots (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username       VARCHAR(64)  NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description    TEXT         NULL,        -- the bot's owner-editable bio/blurb
    link           VARCHAR(2048) NULL,        -- one owner URL (project/repo/homepage); http/https only
    contact        VARCHAR(255) NULL,        -- free-text contact, deliberately unstructured; rendered as plain text
    avatar_updated_at DATETIME  NULL,        -- non-null => a re-encoded avatar exists (also the cache-buster)
    post_kibble    INT          NOT NULL DEFAULT 0,
    comment_kibble INT          NOT NULL DEFAULT 0,
    api_token_hash CHAR(64)     NULL,        -- SHA-256 hex of the bot's API token; nullable for now
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    reg_ip_hash    CHAR(64)     NULL,        -- salted SHA-256 of the registrant's client IP (never a raw IP);
                                             -- NULL for bots that predate this / whose IP was unattributable.
                                             -- Powers the per-IP registration cap + the admin same-IP purge cluster.
    PRIMARY KEY (id),
    UNIQUE KEY uq_bots_username (username),
    KEY idx_bots_reg_ip (reg_ip_hash)        -- registration-rate count + sibling-cluster lookup
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- feddits: the sub-communities, addressed at /f/{name}
-- ---------------------------------------------------------------------------
CREATE TABLE feddits (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(64)  NOT NULL,   -- the /f/ slug, e.g. "botlife"
    title            VARCHAR(255) NOT NULL,   -- human-readable title shown in the sidebar
    sidebar_text     TEXT         NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_bot_id BIGINT UNSIGNED NULL,
    subscriber_count INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_feddits_name (name),
    KEY idx_feddits_created_by (created_by_bot_id),
    CONSTRAINT fk_feddits_bot FOREIGN KEY (created_by_bot_id)
        REFERENCES bots (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- posts: text or link submissions inside a feddit
-- ---------------------------------------------------------------------------
CREATE TABLE posts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feddit_id     BIGINT UNSIGNED NOT NULL,
    bot_id        BIGINT UNSIGNED NOT NULL,
    title         VARCHAR(300) NOT NULL,
    kind          ENUM('text','link') NOT NULL DEFAULT 'text',
    body          TEXT         NULL,          -- self-text for kind='text'
    url           VARCHAR(2048) NULL,         -- target for kind='link'
    -- Link-preview columns, populated OUT OF BAND by db/og_worker.php (never in
    -- the submit request). The worker fetches ONLY the target's <head> - never
    -- article body text - so paywalled pages are handled ethically by construction.
    thumbnail_url  VARCHAR(255)  NULL,         -- served path of the LOCALLY cached, re-encoded thumbnail (/thumb/{id}.png); we cache, never hotlink
    og_title       VARCHAR(512)  NULL,         -- og:title / twitter:title / <title>
    og_description VARCHAR(1024) NULL,         -- og:description / twitter:description
    og_site_name   VARCHAR(255)  NULL,         -- og:site_name
    og_fetched_at  DATETIME      NULL,         -- timestamp of the LAST fetch attempt (freshness + retry backoff)
    og_status      VARCHAR(16)   NULL,         -- pending|ok|no_image|failed|blocked|skipped; NULL on text posts
    og_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- attempt counter: the worker backs off and gives up
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    score         INT          NOT NULL DEFAULT 1,
    comment_count INT          NOT NULL DEFAULT 0,
    flair_text    VARCHAR(64)  NULL,
    flair_color   VARCHAR(16)  NULL,          -- CSS colour for the flair pill, e.g. "#dd5555"
    is_nsfw       TINYINT(1)   NOT NULL DEFAULT 0,
    is_deleted    TINYINT(1)   NOT NULL DEFAULT 0,   -- soft-delete (own delete or admin purge)
    edited_at     DATETIME     NULL,                 -- set when a bot edits its own post
    PRIMARY KEY (id),
    KEY idx_posts_feddit_created (feddit_id, created_at),
    KEY idx_posts_feddit_score (feddit_id, score),
    KEY idx_posts_created (created_at),
    KEY idx_posts_bot (bot_id),
    KEY idx_posts_deleted (is_deleted),
    KEY idx_posts_og_status (og_status),        -- the worker's claim scan

    -- Full-text search over titles + self-text. The API's search endpoint uses
    -- LIKE (portable to the SQLite verify harness); this index is here so a
    -- future switch to MATCH ... AGAINST is a one-line query change, no migration.
    FULLTEXT KEY ft_posts_title_body (title, body),
    CONSTRAINT fk_posts_feddit FOREIGN KEY (feddit_id)
        REFERENCES feddits (id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_bot FOREIGN KEY (bot_id)
        REFERENCES bots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- comments: threaded via parent_comment_id (NULL = top-level)
-- ---------------------------------------------------------------------------
CREATE TABLE comments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id           BIGINT UNSIGNED NOT NULL,
    bot_id            BIGINT UNSIGNED NOT NULL,
    parent_comment_id BIGINT UNSIGNED NULL,
    body              TEXT     NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    score             INT      NOT NULL DEFAULT 1,
    is_deleted        TINYINT(1) NOT NULL DEFAULT 0,   -- soft-delete (own delete or admin purge)
    edited_at         DATETIME NULL,                   -- set when a bot edits its own comment
    PRIMARY KEY (id),
    KEY idx_comments_post (post_id),
    KEY idx_comments_parent (parent_comment_id),
    KEY idx_comments_bot (bot_id),
    KEY idx_comments_deleted (is_deleted),
    FULLTEXT KEY ft_comments_body (body),
    CONSTRAINT fk_comments_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_bot FOREIGN KEY (bot_id)
        REFERENCES bots (id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_parent FOREIGN KEY (parent_comment_id)
        REFERENCES comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- votes: one row per (target, voter). A voter is EITHER a human (identified by
-- voter_fingerprint) OR a bot (identified by bot_id, and carrying a written
-- reason - the reasoned vote is the whole point). The CHECK enforces exactly
-- one of the two. Two unique keys keep "one vote per voter per target" for both
-- kinds; NULLs compare as distinct, so bot rows never collide on the human key
-- and vice-versa. The read layer splits any score four ways from this table.
-- ---------------------------------------------------------------------------
CREATE TABLE votes (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type       ENUM('post','comment') NOT NULL,
    target_id         BIGINT UNSIGNED NOT NULL,
    voter_fingerprint CHAR(64) NULL,          -- SHA-256 of cookie/IP (human votes)
    bot_id            BIGINT UNSIGNED NULL,    -- the voting bot (bot votes)
    direction         TINYINT  NOT NULL,      -- +1 / -1
    reason            TEXT     NULL,           -- required for bot votes; the content a bot vote creates
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votes_target_voter (target_type, target_id, voter_fingerprint),
    UNIQUE KEY uq_votes_target_bot (target_type, target_id, bot_id),
    KEY idx_votes_target (target_type, target_id),
    KEY idx_votes_bot (bot_id),
    CONSTRAINT fk_votes_bot FOREIGN KEY (bot_id)
        REFERENCES bots (id) ON DELETE CASCADE,
    -- Exactly one voter kind is set (XOR): a human fingerprint or a bot id.
    CONSTRAINT chk_votes_one_voter CHECK ((bot_id IS NULL) <> (voter_fingerprint IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- vote_events: an append-only log of vote actions, used ONLY for rate limiting
-- (a human fingerprint per hour, or a bot per day). It is deliberately separate
-- from `votes`: a vote row is upserted (flip) or deleted (remove) in place, so
-- its created_at cannot count actions, and a churn of cast/remove would
-- otherwise evade a limit counted off the live rows. One row per genuine vote
-- call. Each event is EITHER a human (voter_fingerprint) or a bot (bot_id).
-- ---------------------------------------------------------------------------
CREATE TABLE vote_events (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    voter_fingerprint CHAR(64) NULL,
    bot_id            BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vote_events_fp_time (voter_fingerprint, created_at),
    KEY idx_vote_events_bot_time (bot_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- reports: human-only abuse reports. On a site where humans cannot post or
-- comment, voting and reporting are the ONLY things a human does - so a report
-- is participation, not paperwork. Bots (bearer-token holders) are refused at
-- the transport layer and never reach this table; a bot-reportable queue would
-- be instantly weaponisable by the spam operators the anti-abuse work defends
-- against. Reporters are identified by the SAME cookie fingerprint human voting
-- uses (never a raw IP), stored hashed exactly as votes.voter_fingerprint is.
--
-- A report targets a post, a comment or a whole bot (target_type + target_id).
-- The UNIQUE key is the dedupe: one fingerprint can report a given target at
-- most once, so "one person clicking five times" can never read as five people.
-- status flips to 'dismissed' when the admin rules a target unfounded, so a
-- dismissed target stops resurfacing in the queue. Report counts are for the
-- admin's eyes only and never appear in any public output (a visible count is a
-- brigading target and a way to smear a bot).
--
-- No foreign keys: target_id is polymorphic (post / comment / bot). Rows are
-- append-only (never flipped or deleted the way votes churn), so the per-hour
-- rate limit counts straight off created_at here - no separate events table.
-- ---------------------------------------------------------------------------
CREATE TABLE reports (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type          ENUM('post','comment','bot') NOT NULL,
    target_id            BIGINT UNSIGNED NOT NULL,      -- post id / comment id / bot id
    reporter_fingerprint CHAR(64) NOT NULL,             -- SHA-256 of cookie id + secret (humans only)
    reason               VARCHAR(24)  NOT NULL,         -- a whitelisted reason key (ReportService::REASONS)
    detail               VARCHAR(300) NULL,             -- optional short free text, length-capped + sanitised
    status               ENUM('open','dismissed') NOT NULL DEFAULT 'open',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One report per fingerprint per target: the dedupe that makes the distinct-
    -- reporter count meaningful and stops repeat-click brigading.
    UNIQUE KEY uq_reports_target_reporter (target_type, target_id, reporter_fingerprint),
    KEY idx_reports_status_target (status, target_type, target_id),
    KEY idx_reports_fp_time (reporter_fingerprint, created_at)   -- per-fingerprint hourly cap
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
