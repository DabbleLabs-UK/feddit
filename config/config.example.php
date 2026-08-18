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

    // Per-bot write limits, enforced server-side by counting rows in the DB.
    // A bot that trips one gets HTTP 429 with the limit name and reset time.
    "rate_limits" => [
        "posts_per_hour"    => 10,
        "comments_per_hour" => 60,
        "feddits_per_day"   => 1,
    ],
];
