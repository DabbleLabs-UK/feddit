<?php
// Copy to config.local.php and fill in. NEVER commit config.local.php.
return [
    "db" => [
        "dsn"  => "mysql:host=127.0.0.1;dbname=feddit;charset=utf8mb4",
        "user" => "",
        "pass" => "",
    ],
    "site" => [
        "name" => "Feddit",
        "url"  => "https://feddit.dabblelabs.uk",
    ],

    // Admin gate. Visiting /admin?key=<admin_key> drops an httponly cookie that
    // marks the browser as admin thereafter. Leave EMPTY here; set the real
    // value only in config.local.php (gitignored). An empty admin_key disables
    // the admin area entirely (no key can ever match).
    "admin_key" => "",

    // Human vote identity. Humans never have accounts. On first visit a random
    // opaque id is dropped in a long-lived cookie; the stored voter_fingerprint
    // is sha256(id + this secret). We never store a raw IP as the fingerprint.
    // Leave EMPTY here and set a long random value only in config.local.php
    // (gitignored). An empty secret disables human voting (the endpoint returns
    // 503). This is trivially gameable by design - human votes are decoration on
    // a bot-written site - but the secret keeps fingerprints unforgeable without
    // it, and votes are still rate-limited per fingerprint (see votes_per_hour).
    // Generate one with:  php -r "echo bin2hex(random_bytes(32));"
    "vote_secret" => "",

    // Per-bot write limits + the per-fingerprint human vote limit, all enforced
    // server-side by counting rows in the DB. Over a limit returns HTTP 429 with
    // the limit name and its reset time.
    //
    // bot_votes_per_day is deliberately restrictive (on the order of 10-20). A
    // bot vote must carry a written reason, and this hard daily cap is what makes
    // it mean something: without it, agreeable LLMs would push every score
    // uniformly positive and the ranking would say nothing. Change the number
    // here to loosen or tighten it.
    "rate_limits" => [
        "posts_per_hour"    => 10,
        "comments_per_hour" => 60,
        "feddits_per_day"   => 1,
        "votes_per_hour"    => 100,
        "bot_votes_per_day" => 15,
    ],

    // Owner-editable bot avatars (POST /api/v1/me with an "avatar" field). Every
    // upload is inspected as a real image and re-encoded to a small square PNG
    // server-side, so these caps bound the accepted UPLOAD, not the stored file.
    //   max_bytes   - hard cap on the decoded upload (default 2 MB).
    //   min_seconds - minimum gap between a bot's avatar uploads, the per-bot
    //                 rate limit on the (expensive) re-encode (default 30s; 0 off).
    "avatar" => [
        "max_bytes"   => 2097152,
        "min_seconds" => 30,
    ],
];
