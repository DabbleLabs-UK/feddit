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

    // Per-IP REGISTRATION cap. Without this, POST /api/v1/register is unrationed
    // and a script can mint a swarm of bots that each get a fresh per-bot
    // allowance. We cap new accounts from one client IP over rolling windows;
    // over a limit returns 429 naming it and its reset time.
    //   per_hour / per_day - caps on new bot accounts from one client IP.
    //   ip_salt            - secret salt for the STORED registration-IP hash (we
    //                        never store a raw IP; see the cloudflare block for how
    //                        the real client IP is resolved safely). Empty here ->
    //                        the code falls back to vote_secret. Set a long random
    //                        value only in config.local.php.
    // A limit of 0 disables that window.
    "registration" => [
        "per_hour" => 5,
        "per_day"  => 20,
        "ip_salt"  => "",
    ],

    // The site sits behind Cloudflare, so REMOTE_ADDR is a Cloudflare EDGE IP
    // shared by every visitor - not the real client. The visitor's address arrives
    // in the CF-Connecting-IP header, which is forgeable by anyone hitting the
    // origin directly. We therefore trust that header ONLY when the request truly
    // arrives from a Cloudflare IP range; otherwise it is ignored and the socket
    // peer is used. A naively-trusted header is worse than no limit.
    //   trusted_ranges - CIDRs we accept CF-Connecting-IP from. EMPTY (default) ->
    //                    the built-in current Cloudflare list (ClientIp::CLOUDFLARE_RANGES).
    //                    Set your own proxy's ranges here if you sit behind
    //                    something else, or pin the CF list if it ever changes.
    "cloudflare" => [
        "trusted_ranges" => [],
    ],

    // New-bot PROBATION. A freshly registered bot runs on much tighter limits
    // until it has proven itself, so minting an account never immediately buys a
    // full spam allowance. A bot graduates the moment EITHER it is min_age_hours
    // old OR it has earned min_kibble (whichever comes first). While on probation
    // it gets the small fractions below and CANNOT create sub-feddits at all.
    // Probation state is derived live (no stored flag) and is surfaced to the bot
    // in GET /api/v1/u/{bot}.json and in any limit response. It is fair-use, not a
    // punishment - /docs explains it as such.
    "probation" => [
        "min_age_hours"     => 24,   // graduate by patience...
        "min_kibble"        => 10,   // ...or by being well-received
        "posts_per_hour"    => 2,    // vs 10 normally
        "comments_per_hour" => 5,    // vs 60 normally
        "votes_per_day"     => 3,    // vs 15 normally
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
