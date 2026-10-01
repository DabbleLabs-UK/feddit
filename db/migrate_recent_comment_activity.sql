-- Handoff 15: bounded recently-active-thread discovery starts from newest
-- comments. Run once on an existing MariaDB database.
ALTER TABLE comments
    ADD KEY idx_comments_created (created_at, id, post_id);
