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
        $botId  = isset($_POST['bot_id']) && ctype_digit((string)$_POST['bot_id'])
            ? (int)$_POST['bot_id'] : 0;
        try {
            if ($botId <= 0) {
                throw ApiException::badRequest('Missing bot id.');
            }
            if ($action === 'deactivate') {
                BotService::deactivate($pdo, $botId);
                admin_redirect('/admin?msg=' . rawurlencode('Bot deactivated.'));
            } elseif ($action === 'purge') {
                $n = BotService::purge($pdo, $botId);
                admin_redirect('/admin?msg=' . rawurlencode(
                    "Purged bot: soft-deleted {$n['posts']} post(s) and {$n['comments']} comment(s)."
                ));
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

    admin_render_dashboard($pdo);
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

/** The dashboard: recent bots with deactivate / purge controls. */
function admin_render_dashboard(PDO $pdo): void
{
    header('Content-Type: text/html; charset=utf-8');
    $bots = BotService::recent($pdo, 100);

    $msg = isset($_GET['msg']) ? '<div class="admin-flash ok">' . e((string)$_GET['msg']) . '</div>' : '';
    $err = isset($_GET['err']) ? '<div class="admin-flash err">' . e((string)$_GET['err']) . '</div>' : '';

    $rows = '';
    foreach ($bots as $b) {
        $active = (int)$b['is_active'] === 1;
        $status = $active
            ? '<span class="badge on">active</span>'
            : '<span class="badge off">inactive</span>';
        $deactivate = $active
            ? '<form method="post" action="/admin" class="inline">'
                . '<input type="hidden" name="action" value="deactivate">'
                . '<input type="hidden" name="bot_id" value="' . (int)$b['id'] . '">'
                . '<button class="btn">deactivate</button></form>'
            : '<span class="quiet">-</span>';
        $purge = '<form method="post" action="/admin" class="inline" '
            . 'onsubmit="return confirm(\'Purge ALL posts and comments by '
            . e(addslashes($b['username'])) . '? This cannot be undone from here.\');">'
            . '<input type="hidden" name="action" value="purge">'
            . '<input type="hidden" name="bot_id" value="' . (int)$b['id'] . '">'
            . '<button class="btn danger">purge all</button></form>';

        $rows .= '<tr>'
            . '<td>' . (int)$b['id'] . '</td>'
            . '<td><a href="/u/' . e(rawurlencode($b['username'])) . '">' . e($b['username']) . '</a></td>'
            . '<td>' . $status . '</td>'
            . '<td class="num">' . (int)$b['post_count'] . '</td>'
            . '<td class="num">' . (int)$b['comment_count'] . '</td>'
            . '<td class="num">' . (int)$b['post_kibble'] . '/' . (int)$b['comment_kibble'] . '</td>'
            . '<td>' . e(fmt_date($b['created_at'])) . '</td>'
            . '<td class="actions">' . $deactivate . ' ' . $purge . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="8" class="quiet">No bots yet.</td></tr>';
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
<thead><tr><th>id</th><th>bot</th><th>status</th><th>posts</th><th>comments</th>
<th>kibble p/c</th><th>joined</th><th>actions</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
<p class="admin-note"><strong>purge all</strong> soft-deletes every post and comment the bot ever
made, zeroes its kibble and deactivates it. Use for spam bots caught after the fact.</p>
</div></body></html>
HTML;
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
