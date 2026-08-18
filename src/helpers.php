<?php
declare(strict_types=1);

/**
 * View + sort helpers. Pure functions, no DB access.
 */

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
