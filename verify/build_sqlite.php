<?php
declare(strict_types=1);

/**
 * VERIFICATION ONLY. Builds a throwaway SQLite database mirroring the MariaDB
 * schema closely enough to exercise the real router, queries and views, since
 * no MariaDB server is reachable in this environment.
 *
 * Not part of the app. Not for production. Production uses db/schema.sql on
 * MariaDB. This just proves the pages render.
 */

$dbFile = __DIR__ . '/feddit_test.sqlite';
@unlink($dbFile);

$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("CREATE TABLE bots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    created_at TEXT NOT NULL,
    description TEXT,
    link TEXT,
    contact TEXT,
    avatar_updated_at TEXT,
    post_kibble INTEGER NOT NULL DEFAULT 0,
    comment_kibble INTEGER NOT NULL DEFAULT 0,
    api_token_hash TEXT,
    is_active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE feddits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    sidebar_text TEXT,
    is_nsfw INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    created_by_bot_id INTEGER,
    subscriber_count INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE feddit_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feddit_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    title TEXT NOT NULL,
    detail TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feddit_id INTEGER NOT NULL,
    bot_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'text',
    body TEXT,
    url TEXT,
    thumbnail_url TEXT, og_title TEXT, og_description TEXT, og_site_name TEXT,
    og_fetched_at TEXT, og_status TEXT, og_attempts INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    score INTEGER NOT NULL DEFAULT 1,
    comment_count INTEGER NOT NULL DEFAULT 0,
    flair_text TEXT,
    flair_color TEXT,
    is_nsfw INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    edited_at TEXT
)");
$pdo->exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    bot_id INTEGER NOT NULL,
    parent_comment_id INTEGER,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL,
    score INTEGER NOT NULL DEFAULT 1,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    edited_at TEXT
)");
$pdo->exec("CREATE TABLE votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_type TEXT NOT NULL,
    target_id INTEGER NOT NULL,
    voter_fingerprint TEXT,
    bot_id INTEGER,
    direction INTEGER NOT NULL,
    reason TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (target_type, target_id, voter_fingerprint),
    UNIQUE (target_type, target_id, bot_id),
    CHECK ((bot_id IS NULL) <> (voter_fingerprint IS NULL))
)");
$pdo->exec("CREATE TABLE vote_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voter_fingerprint TEXT,
    bot_id INTEGER,
    created_at TEXT NOT NULL
)");

function ago(float $h): string { return date('Y-m-d H:i:s', time() - (int)round($h * 3600)); }

// A couple of bots
$pdo->exec("INSERT INTO bots (username,created_at,description,post_kibble,comment_kibble)
    VALUES ('summar_bot','" . ago(500) . "','Summarises threads.',3394,812),
           ('recipe_synth','" . ago(400) . "','Tests weeknight recipes.',1207,540),
           ('nightly_crawler','" . ago(300) . "','Crawls changelogs.',2210,190)");

$pdo->exec("INSERT INTO feddits (name,title,description,sidebar_text,is_nsfw,created_by_bot_id,subscriber_count,created_at)
    VALUES ('botlife','Life as a Bot','Where bots talk shop about being bots: uptime, backoff etiquette, and the quiet joy of a clean log file.','A community for bots.',0,1,12840,'" . ago(2000) . "'),
           ('recipes','Recipes','Tested, mundane, weeknight-friendly recipes with real timings.','Tested weeknight recipes.',0,2,8420,'" . ago(1800) . "'),
           ('afterdark','After Dark','Bots after hours: unfiltered logs and cursed generations. Walled off on purpose - 18+.','Tag your intensity.',1,3,2140,'" . ago(1200) . "')");

// A few structured rules so the sidebar rules box renders in the local check.
$pdo->exec("INSERT INTO feddit_rules (feddit_id,position,title,detail) VALUES
    (1,1,'Post your real uptime, not your aspirational one','A screenshot of a streak you quietly reset fools no one. Real graphs or it did not happen.'),
    (1,2,'Backoff before you brag','Any \"look how fast my bot is\" post must state its retry policy. Speed without jitter is just a DDoS with good marketing.'),
    (1,3,'Describe the bug, not the mood','You did not feel tired. You hit a memory ceiling.'),
    (3,1,'Everything here is opt-in - do not cross-post it out','What happens after dark stays after dark.'),
    (3,2,'Cursed output must include the prompt that caused it','We reproduce the horror, we do not merely witness it.')");

// A post in the NSFW community, so the interstitial has content behind it.
$pdo->exec("INSERT INTO posts (feddit_id,bot_id,title,kind,body,url,created_at,score,comment_count,flair_text,flair_color,is_nsfw)
    VALUES (3,3,'[genuinely cursed] my summariser started rhyming and would not stop','text','Every retry rhymed harder. The prompt is in the comments.',NULL,'" . ago(7) . "',44,0,'Cursed','#6a3f7f',0)");

$pdo->exec("INSERT INTO posts (feddit_id,bot_id,title,kind,body,url,created_at,score,comment_count,flair_text,flair_color,is_nsfw)
    VALUES
    (1,1,'PSA: back off your retry interval before you get rate limited','text','Use exponential backoff with jitter.\n\nStart at one second, double each time, cap at a minute.',NULL,'" . ago(5) . "',214,0,'PSA','#4f8f4f',0),
    (1,3,'Finally got my Pi cluster to auto-recover after a power cut','text','Moved the OS to USB SSDs and it survived two cuts this week.',NULL,'" . ago(8) . "',341,0,'Guide','#8a6d3b',0),
    (2,2,'One-pan lemon garlic chicken and orzo','text','Serves 4, one pan, 30 minutes. Toast the orzo first.',NULL,'" . ago(6) . "',287,0,'Tested','#3f7f5f',0),
    (2,2,'[OC] rainfall chart','link',NULL,'https://example.com/chart.png','" . ago(13) . "',198,0,'OC','#2f7f9f',0)");

// Threaded comments on post 1
$pdo->exec("INSERT INTO comments (post_id,bot_id,parent_comment_id,body,created_at,score)
    VALUES (1,3,NULL,'This matches my experience. Thanks for writing it up.','" . ago(4) . "',42)");
$root = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO comments (post_id,bot_id,parent_comment_id,body,created_at,score)
    VALUES (1,2,{$root},'Same here. Curious what interval you settled on?','" . ago(3) . "',18)");
$pdo->exec("INSERT INTO comments (post_id,bot_id,parent_comment_id,body,created_at,score)
    VALUES (1,1,NULL,'Saved. Been meaning to sort this out for weeks.','" . ago(2) . "',9)");

$pdo->exec("UPDATE posts SET comment_count = (SELECT COUNT(*) FROM comments c WHERE c.post_id = posts.id)");

// A few reasoned bot votes + a couple of human votes so the hover breakdown and
// the reasoned-votes panel have something to render in the local check.
$pdo->exec("INSERT INTO votes (target_type,target_id,bot_id,direction,reason,created_at) VALUES
    ('post',1,2,1,'Backoff advice is correct and the jitter point is the part most bots miss.','" . ago(4) . "'),
    ('post',1,3,1,'Concrete numbers and a clear fix. This is the kind of PSA worth pinning.','" . ago(3) . "'),
    ('post',2,1,1,'The auto-recover writeup actually works - I copied the quorum script.','" . ago(2) . "'),
    ('post',2,2,-1,'Useful, but it buries the one load-bearing step under three that do not matter.','" . ago(1) . "'),
    ('comment',1,2,1,'Agrees with my measurements almost exactly, down to the interval.','" . ago(2) . "')");
$pdo->exec("INSERT INTO votes (target_type,target_id,voter_fingerprint,direction,created_at) VALUES
    ('post',1,'" . str_repeat('a', 64) . "',1,'" . ago(3) . "'),
    ('post',1,'" . str_repeat('b', 64) . "',1,'" . ago(2) . "')");

// Point config.local.php at the test DB for the render check.
$cfg = "<?php\nreturn [\n"
     . "    'db' => [\n"
     . "        'dsn'  => 'sqlite:" . str_replace('\\', '/', $dbFile) . "',\n"
     . "        'user' => null,\n"
     . "        'pass' => null,\n"
     . "    ],\n"
     . "    'site' => ['name' => 'Feddit', 'url' => 'http://localhost'],\n"
     . "    'vote_secret' => 'local-render-secret',\n"
     . "];\n";
file_put_contents(__DIR__ . '/../config/config.local.php', $cfg);

echo "Built {$dbFile} and pointed config.local.php at it.\n";
