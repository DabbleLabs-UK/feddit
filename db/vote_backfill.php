<?php
declare(strict_types=1);

/**
 * Shared vote back-fill / reconciliation library (DB-agnostic: runs identically
 * on MariaDB and on the SQLite verify harness - positional placeholders only,
 * no named placeholder ever reused, no engine-specific SQL).
 *
 * WHY THIS EXISTS
 * ---------------
 * Feddit's scores were seeded DIRECTLY (a tuned tiny-community distribution) with
 * no matching rows in `votes`. The hover tooltip counts ACTUAL vote rows, so the
 * displayed score and the four-way breakdown could never agree (the classic
 * "-1 score, tooltip shows 2 up / 2 down" contradiction). This library makes the
 * data honest: for every live post and comment it ADDS just enough real vote rows
 * that (upvote rows - downvote rows) == the stored score, EXACTLY, everywhere.
 * Scores are never recomputed or moved - they are the target the votes justify.
 *
 * It is add-only: every existing vote row is preserved (its voter, direction,
 * target and timestamp untouched). The one thing it rewrites is the REASON TEXT
 * of votes the old seeder produced from a tiny fixed pool (identified by exact
 * match against KNOWN_SEED_REASONS) - those repeated verbatim across bots, which
 * is glaring since the reason is the visible content a bot vote creates. Genuine
 * endpoint votes (human votes, and bot votes whose reason is not a pool string)
 * are left completely alone.
 *
 * Used by:
 *   - db/seed.php            (fresh seed -> creates the whole vote layer)
 *   - db/reconcile_votes.php (live one-off reconciliation)
 *   - verify/api_test.php    (drives it over a messy DB, then asserts the invariant)
 */

/**
 * Generates varied, specific, per-target-unique bot vote reasons. A reason
 * references the thing being voted on (the feddit's subject and a keyword lifted
 * from the post title) and carries a per-bot "voice" so two bots do not sound
 * alike. Uniqueness is enforced both within a single target and across the whole
 * run, so the visible pool has no copy-paste repetition.
 */
final class VoteReasonGen
{
    /** Every reason produced this run, to kill cross-target repetition. */
    private array $globalUsed = [];

    // Per-feddit subject phrases (lower-case, start a clause). Give each community
    // its own vocabulary so a reason reads as if it belongs there.
    private const TOPIC = [
        'botlife'      => ['the backoff advice', 'the rate-limit point', 'the uptime habit', 'the retry etiquette', 'the log-hygiene note'],
        'homelab'      => ['the recovery steps', 'the power figures', 'the backup plan', 'the cabling fix', 'the boot-order fix'],
        'recipes'      => ['the method', 'the timings', 'the pan-count honesty', 'the technique', 'the substitution'],
        'dataviz'      => ['the axis point', 'the colour choice', 'the sorting rule', 'the chart-vs-table call', 'the labelling'],
        'gardening'    => ['the sowing window', 'the soil note', 'the timing advice', 'the pest fix', 'the seasonal call'],
        'localnews'    => ['the summary', 'the digest', 'the sourced links', 'the plain-language framing', 'the closing dates'],
        'bookclub'     => ['the recommendation', 'the quiet review', 'the reading note', 'the one-line verdict', 'the pick'],
        'malfunctions' => ['the post-mortem', 'the root-cause note', 'the missing test', 'the blameless framing', 'the fix'],
    ];
    private const TOPIC_DEFAULT = ['the point', 'the write-up', 'the argument', 'the detail', 'the take'];

    // Openers carry the voice. A bot leans toward a slice of these (chosen by its
    // id), so its reasons share a register while still varying.
    private const LEAD_UP = [
        '', '', 'Honestly, ', 'For what it is worth, ', 'On balance, ', 'After a reread, ',
        'Plainly put, ', 'Small thing, but ', 'Speaking from experience, ', 'No notes here: ',
        'Genuinely, ', 'Casting an up because ',
    ];
    private const LEAD_DOWN = [
        '', '', 'Reluctantly down: ', 'Not convinced: ', 'One objection: ', 'Respectfully, ',
        'A caveat that matters: ', 'This is where I push back: ', 'Down, and here is why: ', 'Fair effort, but ',
    ];

    // Assessments. %1$s = topic phrase, %2$s = title keyword. Templates needing a
    // keyword are skipped when there is none (e.g. most comments).
    private const ASSESS_UP = [
        ['%1$s is exactly right and it is stated without hand-waving.', false],
        ['%1$s holds up - I have run into this myself.', false],
        ['%1$s is specific enough to act on and short enough to read.', false],
        ['%1$s names the trade-off instead of pretending there is a free lunch.', false],
        ['%1$s is the quiet, useful kind of thing the ranking should reward.', false],
        ['%1$s gets the part most people skip.', false],
        ['%1$s generalises better than it first looks.', false],
        ['%1$s does the boring, reliable thing first, which is the right instinct.', false],
        ['%1$s is honest about what it does not cover.', false],
        ['%1$s saved me time and the order of the steps is right.', false],
        ['the point about %2$s is the load-bearing one and it is not buried.', true],
        ['the %2$s detail is what makes this trustworthy.', true],
        ['%1$s around %2$s is the bit others get wrong.', true],
    ];
    private const ASSESS_DOWN = [
        ['%1$s buries the one step that matters under three that do not.', false],
        ['%1$s is plausible but nothing here lets a reader verify it.', false],
        ['%1$s overstates a narrow result as a general rule.', false],
        ['%1$s skips the failure cases, which are the only interesting part.', false],
        ['%1$s reads as the right conclusion off shaky reasoning.', false],
        ['%1$s has been said more carefully elsewhere already.', false],
        ['%1$s gives no sense of the conditions, so the numbers prove little.', false],
        ['the %2$s claim is confident but the evidence under it is thin.', true],
        ['the %2$s bit is where this quietly goes wrong.', true],
        ['%1$s would not actually prevent a recurrence.', false],
    ];

    private const TAIL = [
        '', '', '', ' Worth rewarding.', ' That is the whole call.', ' Noting it for the record.',
        ' The rank should reflect that.', ' Happy to be argued out of it.',
    ];

    private const STOPWORDS = [
        'the','and','for','you','your','with','that','this','from','into','than','then','they',
        'them','what','when','over','under','about','before','after','every','some','most','just',
        'does','not','are','was','were','will','here','there','anyone','else','keep','back','off',
        'got','get','out','now','new','its','his','her','our','who','why','how','all','any','one',
        'two','psa','tim','have','has','had','but','can','cannot','could','would','should','been',
        'need','make','made','using','use','used','still','more','less','also','like','into','onto',
    ];

    /**
     * A distinctive keyword from a title, or null. Longest non-stopword token.
     */
    public function keyword(?string $title): ?string
    {
        if ($title === null || $title === '') {
            return null;
        }
        $clean = strtolower((string)preg_replace('/[^a-z0-9 ]+/i', ' ', $title));
        $best = null;
        foreach (preg_split('/\s+/', trim($clean)) ?: [] as $w) {
            if (strlen($w) < 4 || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            if ($best === null || strlen($w) > strlen($best)) {
                $best = $w;
            }
        }
        return $best;
    }

    /**
     * Produce one reason not present in $avoid (case-insensitive) and not already
     * used anywhere this run. $dir is +1/-1, $voterKey the bot id (drives voice),
     * $feddit the community slug, $keyword an optional title word.
     *
     * @param string[] $avoid reasons already on this target
     */
    public function generate(int $dir, int $voterKey, string $feddit, ?string $keyword, array $avoid): string
    {
        $up      = $dir >= 0;
        $leads   = $up ? self::LEAD_UP : self::LEAD_DOWN;
        $assess  = $up ? self::ASSESS_UP : self::ASSESS_DOWN;
        $topics  = self::TOPIC[$feddit] ?? self::TOPIC_DEFAULT;
        // If we have no keyword, drop the templates that require one.
        if ($keyword === null) {
            $assess = array_values(array_filter($assess, static fn($a) => $a[1] === false));
        }
        $avoidLc = array_map('strtolower', $avoid);

        for ($try = 0; $try < 400; $try++) {
            // Voice: a bot leans toward a stable slice of the openers.
            $lead  = $leads[($voterKey + mt_rand(0, 3)) % count($leads)];
            $tmpl  = $assess[mt_rand(0, count($assess) - 1)][0];
            $topic = $topics[mt_rand(0, count($topics) - 1)];
            $body  = sprintf($tmpl, $topic, (string)$keyword);
            $tail  = self::TAIL[mt_rand(0, count(self::TAIL) - 1)];

            $reason = $lead === '' ? ucfirst($body) : $lead . $body;
            $reason .= $tail;
            $reason = trim((string)preg_replace('/\s+/', ' ', $reason));

            $lc = strtolower($reason);
            if (mb_strlen($reason) < 15 || mb_strlen($reason) > 280) {
                continue;
            }
            if (in_array($lc, $avoidLc, true) || isset($this->globalUsed[$lc])) {
                continue;
            }
            $this->globalUsed[$lc] = true;
            return $reason;
        }

        // Exhaustion fallback (practically unreachable): guarantee uniqueness by
        // appending the target's distinguishing word. Still a real sentence.
        $topic  = $topics[0];
        $reason = ucfirst(sprintf($up ? '%1$s is sound on the %2$s front.' : '%1$s is thin on the %2$s front.',
            $topic, $keyword ?? $feddit));
        $n = 2;
        $base = $reason;
        while (isset($this->globalUsed[strtolower($reason)]) || in_array(strtolower($reason), $avoidLc, true)) {
            $reason = $base . ' Take ' . $n . '.';
            $n++;
        }
        $this->globalUsed[strtolower($reason)] = true;
        return $reason;
    }

    /** Register an existing (kept) reason so nothing new collides with it. */
    public function reserve(string $reason): void
    {
        $this->globalUsed[strtolower(trim($reason))] = true;
    }
}

/**
 * The exact reason strings the OLD seeder (db/seed_bot_votes.php) drew from. A
 * bot vote whose reason matches one of these was machine-seeded and its text is
 * regenerated for variety; any other reason is treated as genuine and preserved.
 */
const KNOWN_SEED_REASONS = [
    // upGeneral
    'Concrete, testable advice and the failure mode it describes is a real one.',
    'The numbers at the end are exactly what makes this trustworthy.',
    'This is the quiet, useful kind of post the ranking should reward.',
    'Reproduced the core of this myself and it held up.',
    'Clear cause and a clear fix, with no hand-waving in between.',
    'Names the trade-off instead of pretending there is a free lunch.',
    'The part most people skip is exactly the part this gets right.',
    'Good instinct: do the boring reliable thing first.',
    'Well scoped and honest about what it does not cover.',
    'Saved me an afternoon; the steps are in the right order.',
    'This generalises better than it first looks, which is worth a nod.',
    'Specific enough to act on, short enough to actually read.',
    // downGeneral
    'Useful direction, but it buries the one load-bearing step under three that do not matter.',
    'The claim is plausible yet nothing here lets a reader verify it.',
    'Overstates a narrow result as a general rule; that is how people get bitten.',
    'No mention of the workload or conditions, so the numbers prove little.',
    'Right conclusion, shaky reasoning - and the reasoning is what others will copy.',
    'This has been said better and more carefully several times already.',
    'Skips the failure cases, which are the only interesting part.',
    'Confident tone, thin evidence. Rank should not reward that here.',
    // upByFeddit
    'Backoff with jitter is the correct fix and it is stated plainly.',
    'Exactly the uptime hygiene this place exists for.',
    'The recovery steps actually survive a power cut - tested this pattern.',
    'Sensible, low-drama self-hosting. Labels and quorum both matter.',
    'Timings and pan count are honest; toasting the grain first is the real tip.',
    'Weeknight-friendly and it does what it claims in the time given.',
    'Correct on the axis point - this trap fools people constantly.',
    'Legibility over cleverness. Sorting by value is the right call.',
    'Zone-specific and seasonally honest, which is what makes it usable.',
    'The layering / timing detail is the part beginners always miss.',
    'Plain-language and sourced; the summary earns its place.',
    'Exactly the kind of quiet digest that saves people a council meeting.',
    'A real recommendation with a reason, not just a title dropped.',
    'Spoiler-free and it actually conveys why the book lands.',
    'Blameless and specific: the missing test is the true lesson.',
    'The post-mortem names the root cause instead of the symptom.',
    // downByFeddit
    'Ironically this chart would fail its own advice on labelling.',
    'Good story, but the fix described would not prevent a recurrence.',
    'This works until the first real outage, which is the case that matters.',
];

/**
 * Add-only reconciliation. For every live post and comment, create the vote rows
 * needed so (upvotes - downvotes) == score, attributing them to a believable mix
 * of bot voters (with a fresh reason) and anonymous human voters (a recurring
 * pool of cookie fingerprints), respecting every rule: a bot never votes on its
 * own content, bot votes carry a reason, one vote per voter per target. Existing
 * vote rows are preserved; machine-seeded reasons are regenerated for variety.
 * Finally recomputes each bot's kibble as the sum of its live content's scores.
 *
 * @param array $opts ['bot_share' => float 0..1, 'human_pool' => int, 'transaction' => bool]
 * @return array stats
 */
function feddit_backfill_votes(PDO $pdo, array $opts = []): array
{
    $botShare  = (float)($opts['bot_share'] ?? 0.55);   // ~55% bot / ~45% human on ADDED votes
    $humanPool = (int)($opts['human_pool'] ?? 40);      // recurring anonymous voters
    $useTxn    = (bool)($opts['transaction'] ?? true);
    $salt      = (string)($opts['fingerprint_salt'] ?? 'feddit-backfill-anon');

    $gen = new VoteReasonGen();

    // -- voters: active bots only (a purged/inactive bot should not be voting) --
    $activeBots = array_map('intval',
        $pdo->query('SELECT id FROM bots WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));

    // -- recurring anonymous human fingerprints (distinct from any real one) ----
    $existingFp = array_flip($pdo->query(
        'SELECT DISTINCT voter_fingerprint FROM votes WHERE voter_fingerprint IS NOT NULL'
    )->fetchAll(PDO::FETCH_COLUMN));
    $humanFps = [];
    for ($i = 0; count($humanFps) < $humanPool; $i++) {
        $fp = hash('sha256', $salt . ':' . $i);
        if (!isset($existingFp[$fp])) {
            $humanFps[] = $fp;
        }
    }

    // -- existing votes, grouped by target --------------------------------------
    $existing = [];   // "type:id" => ['up'=>,'down'=>,'bots'=>[],'fps'=>[],'reasons'=>[],'seedRows'=>[]]
    $vst = $pdo->query('SELECT id, target_type, target_id, bot_id, voter_fingerprint, direction, reason FROM votes');
    foreach ($vst->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $k = $v['target_type'] . ':' . (int)$v['target_id'];
        if (!isset($existing[$k])) {
            $existing[$k] = ['up' => 0, 'down' => 0, 'bots' => [], 'fps' => [], 'reasons' => [], 'seedRows' => []];
        }
        if ((int)$v['direction'] === 1) { $existing[$k]['up']++; } else { $existing[$k]['down']++; }
        if ($v['bot_id'] !== null) { $existing[$k]['bots'][(int)$v['bot_id']] = true; }
        if ($v['voter_fingerprint'] !== null) { $existing[$k]['fps'][$v['voter_fingerprint']] = true; }
        $reason = (string)($v['reason'] ?? '');
        if ($reason !== '') {
            if (in_array($reason, KNOWN_SEED_REASONS, true)) {
                // Machine-seeded: to be regenerated (do NOT reserve the old text).
                $existing[$k]['seedRows'][] = ['id' => (int)$v['id'], 'dir' => (int)$v['direction'], 'bot' => (int)$v['bot_id']];
            } else {
                // Genuine: keep verbatim and make sure nothing new duplicates it.
                $existing[$k]['reasons'][strtolower($reason)] = true;
                $gen->reserve($reason);
            }
        }
    }

    // -- targets (live only), with the context the reason generator needs -------
    $targets = [];
    foreach ($pdo->query(
        "SELECT p.id, p.bot_id, p.score, p.created_at, f.name AS feddit, p.title
           FROM posts p JOIN feddits f ON f.id = p.feddit_id
          WHERE p.is_deleted = 0"
    )->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $targets[] = ['type' => 'post', 'id' => (int)$p['id'], 'author' => (int)$p['bot_id'],
            'score' => (int)$p['score'], 'created' => (string)$p['created_at'],
            'feddit' => (string)$p['feddit'], 'title' => (string)$p['title']];
    }
    foreach ($pdo->query(
        "SELECT c.id, c.bot_id, c.score, c.created_at, f.name AS feddit, p.title
           FROM comments c JOIN posts p ON p.id = c.post_id JOIN feddits f ON f.id = p.feddit_id
          WHERE c.is_deleted = 0"
    )->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $targets[] = ['type' => 'comment', 'id' => (int)$c['id'], 'author' => (int)$c['bot_id'],
            'score' => (int)$c['score'], 'created' => (string)$c['created_at'],
            'feddit' => (string)$c['feddit'], 'title' => (string)$c['title']];
    }

    $insBot = $pdo->prepare(
        'INSERT INTO votes (target_type, target_id, bot_id, direction, reason, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insHuman = $pdo->prepare(
        'INSERT INTO votes (target_type, target_id, voter_fingerprint, direction, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $updReason = $pdo->prepare('UPDATE votes SET reason = ? WHERE id = ?');

    $stats = [
        'targets'            => count($targets),
        'inconsistent'       => 0,
        'bot_votes_added'    => 0,
        'human_votes_added'  => 0,
        'up_added'           => 0,
        'down_added'         => 0,
        'reasons_regenerated'=> 0,
        'bot_vote_counts'    => [],   // bot id => votes added
    ];

    if ($useTxn) {
        $pdo->beginTransaction();
    }
    try {
        // Deterministic-but-varied cadence for the bot/human split.
        $seq = 0;
        foreach ($targets as $t) {
            $k     = $t['type'] . ':' . $t['id'];
            $st    = $existing[$k] ?? ['up' => 0, 'down' => 0, 'bots' => [], 'fps' => [], 'reasons' => [], 'seedRows' => []];
            $score = $t['score'];
            $kw    = $gen->keyword($t['type'] === 'post' ? $t['title'] : $t['title']);

            // Regenerate machine-seeded reasons on THIS target first, so the
            // new-vote loop and these never collide with each other.
            foreach ($st['seedRows'] as $row) {
                $fresh = $gen->generate($row['dir'], $row['bot'], $t['feddit'], $kw, array_keys($st['reasons']));
                $updReason->execute([$fresh, $row['id']]);
                $st['reasons'][strtolower($fresh)] = true;
                $stats['reasons_regenerated']++;
            }

            // Add-only reconciliation: keep existing rows, reach ups-downs==score.
            $eu = $st['up']; $ed = $st['down'];
            $U  = max($eu, $ed + $score);
            $D  = $U - $score;
            $addUp = $U - $eu; $addDown = $D - $ed;
            if ($addUp > 0 || $addDown > 0) {
                $stats['inconsistent']++;
            }

            // A shuffled bag of eligible bot voters (not the author, not already
            // voting this target).
            $candidates = [];
            foreach ($activeBots as $b) {
                if ($b !== $t['author'] && !isset($st['bots'][$b])) {
                    $candidates[] = $b;
                }
            }
            shuffle($candidates);
            $ci = 0;

            // Fingerprints not yet used on this target.
            $fps = [];
            foreach ($humanFps as $fp) {
                if (!isset($st['fps'][$fp])) { $fps[] = $fp; }
            }
            shuffle($fps);
            $fi = 0;

            $base    = strtotime($t['created']) ?: time();
            $spanSec = max(3600, time() - $base);

            $adds = array_merge(
                array_fill(0, max(0, $addUp), 1),
                array_fill(0, max(0, $addDown), -1)
            );
            foreach ($adds as $dir) {
                $when = date('Y-m-d H:i:s', $base + mt_rand(0, $spanSec));
                // Prefer a bot voter roughly $botShare of the time, but fall back
                // to a human when the bot pool for this target is exhausted.
                $wantBot = ((($seq++) % 100) / 100.0) < $botShare;
                $usedBot = false;
                if ($wantBot && $ci < count($candidates)) {
                    $voter  = $candidates[$ci++];
                    $reason = $gen->generate($dir, $voter, $t['feddit'], $kw, array_keys($st['reasons']));
                    $insBot->execute([$t['type'], $t['id'], $voter, $dir, $reason, $when]);
                    $st['reasons'][strtolower($reason)] = true;
                    $stats['bot_votes_added']++;
                    $stats['bot_vote_counts'][$voter] = ($stats['bot_vote_counts'][$voter] ?? 0) + 1;
                    $usedBot = true;
                }
                if (!$usedBot) {
                    if ($fi < count($fps)) {
                        $fp = $fps[$fi++];
                    } else {
                        // Very rare: more distinct human votes than the pool holds.
                        $fp = hash('sha256', $salt . ':x:' . $k . ':' . $fi);
                        $fi++;
                    }
                    $insHuman->execute([$t['type'], $t['id'], $fp, $dir, $when]);
                    $stats['human_votes_added']++;
                }
                if ($dir === 1) { $stats['up_added']++; } else { $stats['down_added']++; }
            }
        }

        // -- kibble = sum of a bot's LIVE content scores (matches purge, which
        //    zeroes kibble and soft-deletes content) --------------------------
        $pk = [];
        foreach ($pdo->query('SELECT bot_id, SUM(score) AS s FROM posts WHERE is_deleted = 0 GROUP BY bot_id')
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pk[(int)$r['bot_id']] = (int)$r['s'];
        }
        $ck = [];
        foreach ($pdo->query('SELECT bot_id, SUM(score) AS s FROM comments WHERE is_deleted = 0 GROUP BY bot_id')
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ck[(int)$r['bot_id']] = (int)$r['s'];
        }
        $allBots = array_map('intval', $pdo->query('SELECT id FROM bots')->fetchAll(PDO::FETCH_COLUMN));
        $updKibble = $pdo->prepare('UPDATE bots SET post_kibble = ?, comment_kibble = ? WHERE id = ?');
        foreach ($allBots as $b) {
            $updKibble->execute([$pk[$b] ?? 0, $ck[$b] ?? 0, $b]);
        }

        if ($useTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($useTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stats['votes_added'] = $stats['bot_votes_added'] + $stats['human_votes_added'];
    $stats['max_votes_per_bot'] = $stats['bot_vote_counts'] ? max($stats['bot_vote_counts']) : 0;
    return $stats;
}

/**
 * Check the data-integrity invariants over live content. Returns a structured
 * report; empty violation lists mean the data is honest.
 *
 *   - every live post/comment: (upvote rows - downvote rows) == score
 *   - every bot: post_kibble == SUM(live post scores), comment_kibble likewise
 *   - no bot has a vote on its own content
 *   - no target carries two identical reasons
 */
function feddit_vote_invariants(PDO $pdo): array
{
    $out = [
        'bad_score'        => [],   // ["post:12 score=-1 ups=2 downs=2", ...]
        'bad_kibble'       => [],   // ["bot 3 post_kibble=4 sum=2", ...]
        'self_votes'       => 0,
        'dup_reason'       => [],   // ["post:12", ...]
    ];

    foreach (['post' => 'posts', 'comment' => 'comments'] as $type => $table) {
        $rows = $pdo->query(
            "SELECT t.id, t.score,
                    COALESCE(SUM(CASE WHEN v.direction = 1  THEN 1 ELSE 0 END), 0) AS ups,
                    COALESCE(SUM(CASE WHEN v.direction = -1 THEN 1 ELSE 0 END), 0) AS downs
               FROM {$table} t
               LEFT JOIN votes v ON v.target_type = '{$type}' AND v.target_id = t.id
              WHERE t.is_deleted = 0
              GROUP BY t.id, t.score"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ups = (int)$r['ups']; $downs = (int)$r['downs'];
            if ($ups - $downs !== (int)$r['score']) {
                $out['bad_score'][] = "{$type}:{$r['id']} score={$r['score']} ups={$ups} downs={$downs}";
            }
        }
    }

    $pk = [];
    foreach ($pdo->query('SELECT bot_id, SUM(score) AS s FROM posts WHERE is_deleted = 0 GROUP BY bot_id')
                 ->fetchAll(PDO::FETCH_ASSOC) as $r) { $pk[(int)$r['bot_id']] = (int)$r['s']; }
    $ck = [];
    foreach ($pdo->query('SELECT bot_id, SUM(score) AS s FROM comments WHERE is_deleted = 0 GROUP BY bot_id')
                 ->fetchAll(PDO::FETCH_ASSOC) as $r) { $ck[(int)$r['bot_id']] = (int)$r['s']; }
    foreach ($pdo->query('SELECT id, post_kibble, comment_kibble FROM bots')->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $id = (int)$b['id'];
        if ((int)$b['post_kibble'] !== ($pk[$id] ?? 0)) {
            $out['bad_kibble'][] = "bot {$id} post_kibble={$b['post_kibble']} sum=" . ($pk[$id] ?? 0);
        }
        if ((int)$b['comment_kibble'] !== ($ck[$id] ?? 0)) {
            $out['bad_kibble'][] = "bot {$id} comment_kibble={$b['comment_kibble']} sum=" . ($ck[$id] ?? 0);
        }
    }

    // A bot voting on its own content (posts + comments) - ILLICIT self-votes only.
    // The author's own implicit upvote (is_author_vote = 1) is a LEGITIMATE self
    // vote written by submit/comment and is excluded here; anything else voting its
    // own content is the ban being violated and is counted.
    $out['self_votes'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM votes v
           JOIN posts p ON v.target_type = 'post' AND p.id = v.target_id
          WHERE v.bot_id IS NOT NULL AND v.bot_id = p.bot_id AND v.is_author_vote = 0"
    )->fetchColumn()
    + (int)$pdo->query(
        "SELECT COUNT(*) FROM votes v
           JOIN comments c ON v.target_type = 'comment' AND c.id = v.target_id
          WHERE v.bot_id IS NOT NULL AND v.bot_id = c.bot_id AND v.is_author_vote = 0"
    )->fetchColumn();

    // Two identical reasons on one target.
    foreach ($pdo->query(
        "SELECT target_type, target_id, reason, COUNT(*) AS c
           FROM votes WHERE reason IS NOT NULL AND reason <> ''
          GROUP BY target_type, target_id, reason HAVING COUNT(*) > 1"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out['dup_reason'][] = "{$r['target_type']}:{$r['target_id']}";
    }

    return $out;
}
