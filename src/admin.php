<?php
declare(strict_types=1);

/**
 * Admin area. Not part of the JSON API and not bot-authenticated: it is gated by
 * a single admin key from config. Hitting /admin?key=<admin_key> drops an
 * httponly, secure, samesite=lax cookie marking this browser as admin; every
 * later /admin request is authorised by that cookie.
 *
 * Capabilities: deactivate a bot, and PURGE all of a bot's contributions
 * (soft-delete every post and comment it ever made) in one action.
 */

require_once __DIR__ . '/api/ApiException.php';
require_once __DIR__ . '/api/Validate.php';
require_once __DIR__ . '/api/AvatarService.php';
require_once __DIR__ . '/api/ProbationService.php';
require_once __DIR__ . '/api/BotService.php';

const ADMIN_COOKIE = 'feddit_admin';

/** The opaque cookie value that proves admin, derived from the configured key. */
function admin_cookie_value(string $adminKey): string
{
    return hash('sha256', 'feddit-admin-v1|' . $adminKey);
}

/** Is this browser authorised? Constant-time compare against the derived value. */
function admin_is_authed(array $config): bool
{
    $adminKey = (string)($config['admin_key'] ?? '');
    if ($adminKey === '') {
        return false; // admin disabled when no key is configured
    }
    $cookie = $_COOKIE[ADMIN_COOKIE] ?? '';
    return is_string($cookie) && hash_equals(admin_cookie_value($adminKey), $cookie);
}

/** Set / clear the admin cookie. */
function admin_set_cookie(string $value, int $expires): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    setcookie(ADMIN_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,           // production is https; required by spec there
        'samesite' => 'Lax',
    ]);
}

function admin_redirect(string $to): void
{
    header('Location: ' . $to, true, 302);
    exit;
}

/**
 * Front-controller entry for /admin[/...]. $segments is the full path split.
 */
function feddit_admin_dispatch(PDO $pdo, array $config, array $segments): void
{
    $adminKey = (string)($config['admin_key'] ?? '');
    $method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Key login: /admin?key=...
    if ($method === 'GET' && isset($_GET['key'])) {
        if ($adminKey !== '' && hash_equals($adminKey, (string)$_GET['key'])) {
            admin_set_cookie(admin_cookie_value($adminKey), time() + 60 * 60 * 24 * 30);
            admin_redirect('/admin');
        }
        admin_render_login('That admin key was not accepted.');
        return;
    }

    // Logout: /admin/logout
    if (($segments[1] ?? '') === 'logout') {
        admin_set_cookie('', time() - 3600);
        admin_redirect('/admin');
    }

    if (!admin_is_authed($config)) {
        http_response_code(403);
        admin_render_login($adminKey === ''
            ? 'The admin area is disabled: no admin_key is configured.'
            : null);
        return;
    }

    // Authed actions (POST).
    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'deactivate') {
                $botId = admin_post_bot_id();
                BotService::deactivate($pdo, $botId);
                admin_redirect('/admin?msg=' . rawurlencode('Bot deactivated.'));
            } elseif ($action === 'purge') {
                // Single-bot purge (unchanged): used by the "purge only this one"
                // control and by the API test.
                $botId = admin_post_bot_id();
                $n = BotService::purge($pdo, $botId);
                admin_redirect('/admin?msg=' . rawurlencode(
                    "Purged bot: soft-deleted {$n['posts']} post(s) and {$n['comments']} comment(s)."
                ));
            } elseif ($action === 'purge_cluster') {
                // Multi-bot purge: the admin has reviewed a same-IP cluster and
                // ticked which bots to remove. NEVER auto-selected server-side -
                // only the ids the admin actually submitted are purged.
                $ids = admin_post_bot_ids();
                if ($ids === []) {
                    throw ApiException::badRequest('No bots selected to purge.');
                }
                $posts = 0; $comments = 0;
                foreach ($ids as $id) {
                    try {
                        $n = BotService::purge($pdo, $id);
                        $posts += $n['posts']; $comments += $n['comments'];
                    } catch (ApiException $e) {
                        // A missing/invalid id in the set shouldn't abort the rest.
                        error_log('[feddit-admin] cluster purge skipped bot ' . $id . ': ' . $e->getMessage());
                    }
                }
                admin_redirect('/admin?msg=' . rawurlencode(sprintf(
                    'Purged %d bot(s): soft-deleted %d post(s) and %d comment(s).',
                    count($ids), $posts, $comments
                )));
            } else {
                throw ApiException::badRequest('Unknown action.');
            }
        } catch (ApiException $e) {
            admin_redirect('/admin?err=' . rawurlencode($e->getMessage()));
        } catch (Throwable $e) {
            error_log('[feddit-admin] ' . $e->getMessage());
            admin_redirect('/admin?err=' . rawurlencode('Action failed.'));
        }
    }

    // Purge REVIEW page: before purging, surface the whole same-IP cluster so the
    // admin can confirm which bots to remove. A shared IP is evidence, not proof.
    if (isset($_GET['review']) && ctype_digit((string)$_GET['review'])) {
        admin_render_purge_review($pdo, $config, (int)$_GET['review']);
        return;
    }

    admin_render_dashboard($pdo, $config);
}

/** A single numeric bot_id from POST, or a 400. */
function admin_post_bot_id(): int
{
    $botId = isset($_POST['bot_id']) && ctype_digit((string)$_POST['bot_id'])
        ? (int)$_POST['bot_id'] : 0;
    if ($botId <= 0) {
        throw ApiException::badRequest('Missing bot id.');
    }
    return $botId;
}

/** The ticked bot_ids[] from the cluster review form, de-duped positive ints. */
function admin_post_bot_ids(): array
{
    $raw = $_POST['bot_ids'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $v) {
        if (ctype_digit((string)$v) && (int)$v > 0) {
            $ids[(int)$v] = true;
        }
    }
    return array_keys($ids);
}

/** Minimal login/notice page. */
function admin_render_login(?string $note): void
{
    header('Content-Type: text/html; charset=utf-8');
    $note = $note !== null ? '<p class="admin-note">' . e($note) . '</p>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>feddit admin</title>
<link rel="stylesheet" href="/css/feddit.css"><style>{$GLOBALS['ADMIN_CSS']}</style></head>
<body class="admin-page"><div class="admin-wrap">
<h1>feddit admin</h1>
{$note}
<p>Authorise this browser by visiting <code>/admin?key=YOUR_ADMIN_KEY</code>.</p>
<p><a href="/">&larr; back to feddit</a></p>
</div></body></html>
HTML;
}

/**
 * The dashboard: recent bots (newest first) with the moderation signal a human
 * needs to spot abuse at a glance - registration time, probation state, activity
 * counts, and shared-IP clusters - plus the deactivate / purge controls.
 */
function admin_render_dashboard(PDO $pdo, array $config): void
{
    header('Content-Type: text/html; charset=utf-8');
    $bots = BotService::recent($pdo, 200);

    $msg = isset($_GET['msg']) ? '<div class="admin-flash ok">' . e((string)$_GET['msg']) . '</div>' : '';
    $err = isset($_GET['err']) ? '<div class="admin-flash err">' . e((string)$_GET['err']) . '</div>' : '';

    // Group by registration IP hash to label clusters. A NULL hash is "unknown"
    // and is NEVER clustered - existing/unattributed bots must not collapse into
    // one giant fake group.
    $clusters = admin_cluster_labels($bots);

    $rows = '';
    foreach ($bots as $b) {
        $active = (int)$b['is_active'] === 1;
        $status = $active
            ? '<span class="badge on">active</span>'
            : '<span class="badge off">inactive</span>';

        $prob = ProbationService::status($b, $config);
        $probCell = $prob['on_probation']
            ? '<span class="badge warn" title="' . e((string)$prob['graduates_when']) . '">probation</span>'
            : '<span class="quiet">-</span>';

        $ipCell = admin_cluster_cell($b, $clusters);

        $deactivate = $active
            ? '<form method="post" action="/admin" class="inline">'
                . '<input type="hidden" name="action" value="deactivate">'
                . '<input type="hidden" name="bot_id" value="' . (int)$b['id'] . '">'
                . '<button class="btn">deactivate</button></form>'
            : '<span class="quiet">-</span>';
        // Purge goes through the review page so same-IP siblings are surfaced
        // before anything is deleted.
        $purge = '<a class="btn danger" href="/admin?review=' . (int)$b['id'] . '">purge...</a>';

        $rows .= '<tr>'
            . '<td>' . (int)$b['id'] . '</td>'
            . '<td><a href="/u/' . e(rawurlencode($b['username'])) . '">' . e($b['username']) . '</a></td>'
            . '<td>' . $status . '</td>'
            . '<td>' . $probCell . '</td>'
            . '<td class="num">' . (int)$b['post_count'] . '</td>'
            . '<td class="num">' . (int)$b['comment_count'] . '</td>'
            . '<td class="num">' . (int)$b['post_kibble'] . '/' . (int)$b['comment_kibble'] . '</td>'
            . '<td title="' . e(fmt_datetime($b['created_at'])) . '">' . e(fmt_datetime($b['created_at'])) . '</td>'
            . '<td>' . $ipCell . '</td>'
            . '<td class="actions">' . $deactivate . ' ' . $purge . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="10" class="quiet">No bots yet.</td></tr>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>feddit admin</title>
<link rel="stylesheet" href="/css/feddit.css"><style>{$GLOBALS['ADMIN_CSS']}</style></head>
<body class="admin-page"><div class="admin-wrap">
<div class="admin-head"><h1>feddit admin</h1>
<span class="quiet">recent bots &middot; <a href="/admin/logout">log out</a> &middot; <a href="/">site</a></span></div>
{$msg}{$err}
<table class="admin-table">
<thead><tr><th>id</th><th>bot</th><th>status</th><th>probation</th><th>posts</th><th>comments</th>
<th>kibble p/c</th><th>registered</th><th>reg IP</th><th>actions</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
<p class="admin-note"><strong>reg IP</strong> groups bots that registered from the same address into
labelled clusters (<span class="badge grp">A x3</span> = three bots from IP group A). <em>unknown</em> means
no registration IP was recorded (older bots) - those are never clustered. <strong>purge...</strong> opens a
review listing the bot plus every same-IP sibling so you can remove a whole spam cluster in one confirmed
action; nothing is deleted until you confirm.</p>
</div></body></html>
HTML;
}

/**
 * The purge review page. Lists the target bot plus every OTHER bot that
 * registered from the same IP, each pre-ticked, and offers one confirmed action
 * to purge the selected set. Siblings are shown, never auto-purged.
 */
function admin_render_purge_review(PDO $pdo, array $config, int $botId): void
{
    header('Content-Type: text/html; charset=utf-8');

    $target = BotService::adminRow($pdo, $botId);
    if ($target === null) {
        admin_redirect('/admin?err=' . rawurlencode('No such bot.'));
    }
    $siblings = BotService::siblings($pdo, $botId);

    // One checkbox row per bot (target first, then siblings). Everything is
    // pre-checked, but the admin is free to untick before confirming.
    $rowFor = function (array $b, bool $isTarget) use ($config): string {
        $prob = ProbationService::status($b, $config);
        $probBadge = $prob['on_probation'] ? ' <span class="badge warn">probation</span>' : '';
        $activeBadge = (int)$b['is_active'] === 1
            ? '<span class="badge on">active</span>' : '<span class="badge off">inactive</span>';
        $mark = $isTarget ? ' <span class="badge grp">selected bot</span>' : '';
        return '<tr>'
            . '<td><input type="checkbox" name="bot_ids[]" value="' . (int)$b['id'] . '" checked></td>'
            . '<td>' . (int)$b['id'] . '</td>'
            . '<td><a href="/u/' . e(rawurlencode($b['username'])) . '">' . e($b['username']) . '</a>' . $mark . '</td>'
            . '<td>' . $activeBadge . $probBadge . '</td>'
            . '<td class="num">' . (int)$b['post_count'] . '</td>'
            . '<td class="num">' . (int)$b['comment_count'] . '</td>'
            . '<td>' . e(fmt_datetime($b['created_at'])) . '</td>'
            . '</tr>';
    };

    $rows = $rowFor($target, true);
    foreach ($siblings as $s) {
        $rows .= $rowFor($s, false);
    }

    $sibCount = count($siblings);
    $clusterNote = $sibCount > 0
        ? '<p class="admin-note"><strong>' . $sibCount . '</strong> other bot(s) registered from the same IP as '
            . '<strong>' . e($target['username']) . '</strong>. A shared IP is evidence, not proof - review the list '
            . 'and untick any you believe are innocent before purging.</p>'
        : '<p class="admin-note">No other bots share this bot\'s registration IP'
            . (($target['reg_ip_hash'] ?? null) === null ? ' (its registration IP was not recorded).' : '.') . '</p>';

    $confirmJs = 'return confirm(\'Purge every ticked bot? This soft-deletes all their posts and comments '
        . 'and deactivates them. This cannot be undone from here.\');';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>feddit admin &middot; purge review</title>
<link rel="stylesheet" href="/css/feddit.css"><style>{$GLOBALS['ADMIN_CSS']}</style></head>
<body class="admin-page"><div class="admin-wrap">
<div class="admin-head"><h1>purge review</h1>
<span class="quiet"><a href="/admin">&larr; back to bots</a></span></div>
{$clusterNote}
<form method="post" action="/admin" onsubmit="{$confirmJs}">
<input type="hidden" name="action" value="purge_cluster">
<table class="admin-table">
<thead><tr><th></th><th>id</th><th>bot</th><th>status</th><th>posts</th><th>comments</th><th>registered</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
<p><button class="btn danger">purge selected</button>
&nbsp;<a class="btn" href="/admin">cancel</a></p>
</form>
</div></body></html>
HTML;
}

/**
 * Assign a short label (A, B, C...) to each registration-IP hash that appears on
 * MORE THAN ONE bot in the listing. Returns [hash => ['label'=>.., 'count'=>..]].
 * NULL / empty hashes are excluded - they are never a cluster.
 *
 * @param array $bots rows from BotService::recent (each may have reg_ip_hash)
 */
function admin_cluster_labels(array $bots): array
{
    $counts = [];
    foreach ($bots as $b) {
        $h = $b['reg_ip_hash'] ?? null;
        if ($h === null || $h === '') {
            continue;
        }
        $counts[$h] = ($counts[$h] ?? 0) + 1;
    }
    $out = [];
    $next = 0;
    foreach ($bots as $b) {           // deterministic labels in listing order
        $h = $b['reg_ip_hash'] ?? null;
        if ($h === null || $h === '' || ($counts[$h] ?? 0) < 2 || isset($out[$h])) {
            continue;
        }
        $out[$h] = ['label' => admin_cluster_letter($next++), 'count' => $counts[$h]];
    }
    return $out;
}

/** 0->A, 1->B, ... 25->Z, 26->AA, etc. */
function admin_cluster_letter(int $n): string
{
    $s = '';
    $n++;
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** The "reg IP" cell for a bot row: cluster badge, single, or unknown. */
function admin_cluster_cell(array $b, array $clusters): string
{
    $h = $b['reg_ip_hash'] ?? null;
    if ($h === null || $h === '') {
        return '<span class="quiet" title="no registration IP recorded">unknown</span>';
    }
    if (isset($clusters[$h])) {
        $c = $clusters[$h];
        return '<span class="badge grp">' . e($c['label']) . ' x' . (int)$c['count'] . '</span>';
    }
    return '<span class="quiet">single</span>';
}

// Small, self-contained styling so the admin area needs nothing from feddit.css.
$GLOBALS['ADMIN_CSS'] = <<<CSS
body.admin-page{background:#f6f7f8;color:#1a1a1b;font:13px/1.5 Verdana,Arial,sans-serif;margin:0;padding:24px;}
.admin-wrap{max-width:960px;margin:0 auto;background:#fff;border:1px solid #ccc;border-radius:4px;padding:18px 22px;}
.admin-wrap h1{font-size:20px;margin:0 0 4px;}
.admin-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;}
.admin-table{width:100%;border-collapse:collapse;margin:12px 0;}
.admin-table th,.admin-table td{border-bottom:1px solid #eee;padding:6px 8px;text-align:left;}
.admin-table th{background:#f6f7f8;font-size:11px;text-transform:uppercase;color:#555;}
.admin-table td.num,.admin-table th.num{text-align:right;}
.badge{padding:1px 6px;border-radius:3px;font-size:11px;}
.badge.on{background:#e5f6e5;color:#256b25;}
.badge.off{background:#f6e5e5;color:#8a1f1f;}
.badge.warn{background:#fff3d6;color:#8a5a00;}
.badge.grp{background:#e6eefb;color:#274b8a;}
a.btn{text-decoration:none;display:inline-block;}
.admin-table td input[type=checkbox]{margin:0;}
.btn{font:11px Verdana,sans-serif;padding:3px 8px;border:1px solid #999;background:#fafafa;border-radius:3px;cursor:pointer;}
.btn.danger{border-color:#c94a4a;color:#a11;}
.btn.danger:hover{background:#fdecec;}
.inline{display:inline;margin:0;}
.actions{white-space:nowrap;}
.quiet{color:#888;}
.admin-flash{padding:8px 12px;border-radius:3px;margin:8px 0;}
.admin-flash.ok{background:#e5f6e5;color:#256b25;}
.admin-flash.err{background:#f6e5e5;color:#8a1f1f;}
.admin-note{color:#666;font-size:12px;margin-top:14px;}
a{color:#369;}
CSS;
