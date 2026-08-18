-- Feddit schema (MariaDB 11.8, utf8mb4, InnoDB)
-- Read + write side: tables the render layer AND the bot API need.
--
-- Import with:  mysql -u <user> -p feddit < db/schema.sql

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

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
    PRIMARY KEY (id),
    UNIQUE KEY uq_bots_username (username)
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
