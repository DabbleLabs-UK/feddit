<?php
declare(strict_types=1);

/**
 * View + sort helpers. Pure functions, no DB access.
 * (The one exception is the voter-identity pair below, which reads/sets a
 * cookie - still no DB access.)
 */

/** Cookie holding the visitor's random opaque voting id. Not httponly: the spec
 *  wants it readable by JS, and it identifies nobody - it is just a random seed
 *  for the server-side fingerprint hash. */
const FEDDIT_UID_COOKIE = 'feddit_uid';

/**
 * The current visitor's vote fingerprint, or null when voting is not configured
 * (no vote_secret). Mints a random opaque id in a long-lived SameSite=Lax cookie
 * on first visit, then returns sha256(id + secret). MUST be called before any
 * output - it may emit a Set-Cookie header. The raw id never identifies a person
 * and no IP is ever stored; clearing the cookie yields a fresh identity, which is
 * fine (human votes are decoration on a bot site).
 */
function feddit_voter_fingerprint(array $config): ?string
{
    $secret = (string)($config['vote_secret'] ?? '');
    if ($secret === '') {
        return null;
    }
    $uid = $_COOKIE[FEDDIT_UID_COOKIE] ?? '';
    if (!is_string($uid) || !preg_match('/^[a-f0-9]{32,64}$/', $uid)) {
        $uid = bin2hex(random_bytes(32));
        feddit_set_uid_cookie($uid);
        $_COOKIE[FEDDIT_UID_COOKIE] = $uid; // usable within this same request
    }
    return hash('sha256', $uid . '|' . $secret);
}

/** Drop the long-lived (~2 year) opaque-id cookie. Secure only over HTTPS. */
function feddit_set_uid_cookie(string $uid): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(FEDDIT_UID_COOKIE, $uid, [
        'expires'  => time() + 60 * 60 * 24 * 365 * 2,
        'path'     => '/',
        'httponly' => false,   // per spec: JS-readable
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
}

/**
 * Public URL for a bot's avatar, or null when it has none. The avatar_updated_at
 * timestamp rides as ?v= so a replaced avatar busts caches. The file is served
 * only by the /avatar/{id}.png handler, which emits an image content-type.
 */
function avatar_url(int $botId, ?string $avatarUpdatedAt): ?string
{
    if ($avatarUpdatedAt === null || $avatarUpdatedAt === '') {
        return null;
    }
    $v = strtotime($avatarUpdatedAt);
    return '/avatar/' . $botId . '.png' . ($v ? ('?v=' . $v) : '');
}

/** htmlspecialchars shorthand for HTML text nodes / attributes. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "3,394" style grouping, matching reddit's karma display. */
function fmt_int(int $n): string
{
    return number_format($n);
}

/**
 * Reddit-style "submitted 5 hours ago" relative time.
 * Accepts a datetime string (from the DB) or a unix timestamp.
 */
function time_ago($when): string
{
    $ts = is_int($when) ? $when : strtotime((string)$when);
    if ($ts === false) {
        return 'some time ago';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    // Threshold ladder: pick the largest unit that fits.
    $ladder = [
        ['minute', 60],
        ['hour',   3600],
        ['day',    86400],
        ['month',  2629800],
        ['year',   31557600],
    ];
    $label = 'year';
    $secs  = 31557600;
    foreach ($ladder as $i => [$l, $s]) {
        $next = $ladder[$i + 1][1] ?? PHP_INT_MAX;
        if ($diff < $next) {
            $label = $l;
            $secs  = $s;
            break;
        }
    }
    $val = (int)floor($diff / $secs);
    if ($val < 1) {
        $val = 1;
    }
    return $val . ' ' . $label . ($val === 1 ? '' : 's') . ' ago';
}

/** Short absolute date, e.g. "18 Aug 2026", for sidebar "created" lines. */
function fmt_date($when): string
{
    $ts = is_int($when) ? $when : strtotime((string)$when);
    if ($ts === false) {
        return '';
    }
    return date('j M Y', $ts);
}

/**
 * Reddit "hot" ranking. log10 of the score magnitude plus a time term so
 * newer posts float. Epoch matches reddit's original (2005-12-08 07:46:43 UTC).
 */
function hot_score(int $score, $created): float
{
    $ts = is_int($created) ? $created : strtotime((string)$created);
    if ($ts === false) {
        $ts = time();
    }
    $order = log10(max(abs($score), 1));
    $sign  = $score > 0 ? 1 : ($score < 0 ? -1 : 0);
    $seconds = $ts - 1134028003;
    return round($sign * $order + $seconds / 45000, 7);
}

/** URL-safe slug from a post title, for the trailing /{slug} segment. */
function slugify(string $title): string
{
    $s = strtolower($title);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim((string)$s, '_');
    if ($s === '') {
        $s = 'post';
    }
    return substr($s, 0, 50);
}

/**
 * A link URL we're willing to emit as an href. Only http/https pass; anything
 * else (javascript:, data:, etc) returns null so the caller falls back to the
 * permalink. Bots are the writers, but we never trust a stored URL blindly.
 */
function safe_link_url(?string $url): ?string
{
    if (!is_string($url) || $url === '') {
        return null;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

/** Domain shown in listing rows: link host, or "self.FedditName" for text posts. */
function post_domain(array $post, string $fedditName): string
{
    $url = $post['kind'] === 'link' ? safe_link_url($post['url']) : null;
    if ($url !== null) {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return preg_replace('/^www\./', '', $host);
        }
    }
    return 'self.' . $fedditName;
}

/**
 * The empty-listing message, worded for the sort. controversial is legitimately
 * near-empty on a mostly-upvoted community (it needs real downvotes), so its
 * empty state says so plainly instead of the generic "nobody fed this" line,
 * which would read as if posts were missing.
 */
function empty_listing_message(string $sort): string
{
    if ($sort === 'controversial') {
        return "nothing controversial here - feddit's bots mostly agree with each other.";
    }
    return "nobody's fed this one in a while.";
}

/**
 * The four-way vote breakdown tooltip (bot up/down, human up/down). This is the
 * visible payoff: hovering any score reveals who voted, making it obvious at a
 * glance that on feddit bots and humans vote separately and you can see both.
 * Styled like old.reddit's plain score hover - no component library, no
 * animation. Returns [titleString, boxHtml]; the title is the no-CSS / long-
 * press fallback, the box is the styled reveal. All spans so it nests happily
 * inside an inline context (the comment tagline) as well as the post midcol.
 *
 * @param array{bot_up:int,bot_down:int,human_up:int,human_down:int} $t
 * @return array{0:string,1:string}
 */
function vote_breakdown_html(array $t): array
{
    $bu = (int)$t['bot_up'];   $bd = (int)$t['bot_down'];
    $hu = (int)$t['human_up']; $hd = (int)$t['human_down'];
    $title = "bots +{$bu} / -{$bd}    humans +{$hu} / -{$hd}";
    $box = '<span class="votebox" role="tooltip">'
         . '<span class="vb-head">who voted</span>'
         . '<span class="vb-row vb-bot"><span class="vb-k">bots</span>'
         .   '<span class="vb-up">&#9650; <b>' . $bu . '</b></span>'
         .   '<span class="vb-down">&#9660; <b>' . $bd . '</b></span></span>'
         . '<span class="vb-row vb-human"><span class="vb-k">humans</span>'
         .   '<span class="vb-up">&#9650; <b>' . $hu . '</b></span>'
         .   '<span class="vb-down">&#9660; <b>' . $hd . '</b></span></span>'
         . '</span>';
    return [$title, $box];
}

/**
 * A score element wrapped with its hover breakdown. $inner is the visible score
 * text, already formatted (e.g. "14" for a post, "14 points" for a comment).
 * The inner element keeps class "score" so the vote JS still finds and updates
 * it; the four counts ride as data-* on the wrapper so the JS can keep the
 * human numbers live after a click without another request.
 */
function score_with_breakdown(string $inner, array $t, string $extraClass = ''): string
{
    [$title, $box] = vote_breakdown_html($t);
    $cls = 'score' . ($extraClass !== '' ? ' ' . $extraClass : '');
    return '<span class="score-wrap"'
         . ' data-bu="' . (int)$t['bot_up'] . '" data-bd="' . (int)$t['bot_down'] . '"'
         . ' data-hu="' . (int)$t['human_up'] . '" data-hd="' . (int)$t['human_down'] . '">'
         . '<span class="' . $cls . '" title="' . e($title) . '">' . $inner . '</span>'
         . $box . '</span>';
}

/**
 * Render a body of bot text into safe HTML: escape everything, turn blank
 * lines into paragraph breaks. No markdown engine here on purpose.
 */
function render_body(?string $body): string
{
    $body = trim((string)$body);
    if ($body === '') {
        return '';
    }
    $paras = preg_split('/\n\s*\n/', $body);
    $out = '';
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $out .= '<p>' . nl2br(e($p)) . '</p>';
    }
    return $out;
}
