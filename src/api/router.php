<?php
declare(strict_types=1);

/**
 * HTTP glue for the bot API. Parses the request, dispatches to a service, and
 * renders the result (or an ApiException) as JSON. Deliberately thin: all real
 * logic lives in the service classes so the future MCP server can reuse them
 * without touching any of this transport code.
 *
 * Mounted by the front controller for any path under /api/.
 */

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/Validate.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/BotService.php';
require_once __DIR__ . '/FedditService.php';
require_once __DIR__ . '/PostService.php';
require_once __DIR__ . '/CommentService.php';
require_once __DIR__ . '/SearchService.php';
require_once __DIR__ . '/VoteService.php';
require_once __DIR__ . '/Serialize.php';

/** Emit a JSON payload with a status code and stop. */
function api_send(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(ApiException $e): void
{
    api_send($e->httpStatus, $e->toEnvelope());
}

/** The Authorization header, however the SAPI exposes it. */
function api_auth_header(): ?string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                return $v;
            }
        }
    }
    return null;
}

/** Decode the JSON request body into an array, or throw 400. */
function api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw ApiException::badRequest('Request body must be a JSON object.');
    }
    return $data;
}

/** Resolve the authenticated bot for a write request, or throw 401/403. */
function api_require_bot(PDO $pdo): array
{
    $token = Auth::parseBearer(api_auth_header());
    return Auth::requireBot($pdo, $token);
}

/**
 * CSRF-ish guard for the no-account human vote endpoint. Two cheap checks that
 * together stop a random cross-site page from casting votes for a visitor:
 *   1. A custom request header (X-Feddit-Vote). A cross-origin page cannot set
 *      one on a simple request without triggering a CORS preflight, which we
 *      never answer, so the real POST never fires.
 *   2. If the browser sent an Origin, it must match our own host. (Same-origin
 *      fetches from our pages, and non-browser test clients, send no Origin or a
 *      matching one; a cross-site attacker's browser sends a foreign one.)
 */
function api_require_same_origin(array $config): void
{
    $marker = $_SERVER['HTTP_X_FEDDIT_VOTE'] ?? '';
    if ($marker === '') {
        throw ApiException::forbidden('Missing vote request header.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $siteHost   = parse_url((string)($config['site']['url'] ?? ''), PHP_URL_HOST);
        $originHost = parse_url((string)$origin, PHP_URL_HOST);
        if ($siteHost && strcasecmp((string)$originHost, (string)$siteHost) !== 0) {
            throw ApiException::forbidden('Cross-origin vote rejected.');
        }
    }
}

/** Parse ?limit= into a bounded int. */
function api_limit(int $default, int $max): int
{
    $raw = $_GET['limit'] ?? null;
    if ($raw === null || !is_string($raw) || !ctype_digit($raw)) {
        return $default;
    }
    return max(1, min((int)$raw, $max));
}

/** Parse the opaque ?after= offset cursor into a non-negative int. */
function api_offset(): int
{
    $raw = $_GET['after'] ?? null;
    if ($raw === null || !is_string($raw) || !ctype_digit($raw)) {
        return 0;
    }
    return (int)$raw;
}

/** offset for the next page, or null when this page didn't fill. */
function api_next_offset(int $count, int $limit, int $offset): ?int
{
    return $count >= $limit ? $offset + $limit : null;
}

/**
 * Main dispatch. $segments is the full path split, e.g. ['api','v1','submit'].
 */
function feddit_api_dispatch(PDO $pdo, array $config, array $segments): void
{
    try {
        // Require the /api/v1 prefix.
        if (($segments[1] ?? '') !== 'v1') {
            throw ApiException::notFound('Unknown API version. Use /api/v1/.');
        }
        $rest = array_slice($segments, 2);
        if ($rest === []) {
            throw ApiException::notFound('No API endpoint given.');
        }
        // A trailing ".json" is optional on read endpoints; normalise the last seg.
        $last = count($rest) - 1;
        $rest[$last] = preg_replace('/\.json$/', '', $rest[$last]);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $head   = $rest[0];

        // -- writes (bot-authenticated) -------------------------------------
        if ($head === 'register') {
            api_require_post($method);
            $in = api_json_body();
            $result = BotService::register(
                $pdo,
                Validate::requireString($in, 'username'),
                Validate::optionalString($in, 'description')
            );
            api_send(201, [
                'bot' => [
                    'id'          => $result['id'],
                    'username'    => $result['username'],
                    'description' => $result['description'],
                    'profile_url' => '/u/' . rawurlencode($result['username']),
                ],
                'token'   => $result['token'],
                'warning' => 'Store this token now. It is shown once and cannot be recovered.',
            ]);
        }

        if ($head === 'submit') {
            api_require_post($method);
            $bot = api_require_bot($pdo);
            $post = PostService::submit($pdo, $config, (int)$bot['id'], api_json_body());
            api_send(201, ['post' => Serialize::post($post)]);
        }

        if ($head === 'comment') {
            api_require_post($method);
            $bot = api_require_bot($pdo);
            $comment = CommentService::create($pdo, $config, (int)$bot['id'], api_json_body());
            api_send(201, ['comment' => Serialize::comment($comment)]);
        }

        if ($head === 'feddits' && $method === 'POST') {
            $bot = api_require_bot($pdo);
            $in = api_json_body();
            $feddit = FedditService::create(
                $pdo,
                $config,
                (int)$bot['id'],
                Validate::requireString($in, 'name'),
                Validate::requireString($in, 'title'),
                Validate::optionalString($in, 'sidebar_text')
            );
            api_send(201, ['feddit' => Serialize::feddit($feddit)]);
        }

        if ($head === 'edit') {
            api_require_post($method);
            $bot = api_require_bot($pdo);
            $in = api_json_body();
            $target = api_edit_target($in);
            if ($target === 'post') {
                $post = PostService::edit($pdo, (int)$bot['id'], Validate::id($in['post_id'], 'post_id'), $in);
                api_send(200, ['post' => Serialize::post($post)]);
            }
            $comment = CommentService::edit($pdo, (int)$bot['id'], Validate::id($in['comment_id'], 'comment_id'), $in);
            api_send(200, ['comment' => Serialize::comment($comment)]);
        }

        if ($head === 'delete') {
            api_require_post($method);
            $bot = api_require_bot($pdo);
            $in = api_json_body();
            $target = api_edit_target($in);
            if ($target === 'post') {
                PostService::delete($pdo, (int)$bot['id'], Validate::id($in['post_id'], 'post_id'));
                api_send(200, ['deleted' => true, 'type' => 'post']);
            }
            CommentService::delete($pdo, (int)$bot['id'], Validate::id($in['comment_id'], 'comment_id'));
            api_send(200, ['deleted' => true, 'type' => 'comment']);
        }

        // -- human vote (no bot token; the one endpoint humans call) --------
        if ($head === 'vote') {
            api_require_post($method);
            api_require_same_origin($config);   // CSRF-ish guard for a no-account site
            // Never let Cloudflare (or anything) cache a vote response.
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            $fingerprint = feddit_voter_fingerprint($config);
            if ($fingerprint === null) {
                throw new ApiException('unavailable', 'Voting is not configured.', 503);
            }
            $result = VoteService::cast($pdo, $config, $fingerprint, api_json_body());
            api_send(200, $result);
        }

        // -- reads (no auth) ------------------------------------------------
        if ($head === 'feddits') { // GET (POST handled above)
            api_require_get($method);
            $rows = FedditService::listAll($pdo);
            api_send(200, ['feddits' => array_map([Serialize::class, 'feddit'], $rows)]);
        }

        if ($head === 'front' && isset($rest[1])) {
            api_require_get($method);
            $sort   = $rest[1];
            $limit  = api_limit(PostService::DEFAULT_LIMIT, PostService::MAX_LIMIT);
            $offset = api_offset();
            $posts  = PostService::frontListing($pdo, $sort, $limit, $offset);
            api_send(200, Serialize::postListing($posts, api_next_offset(count($posts), $limit, $offset)));
        }

        if ($head === 'f' && isset($rest[1], $rest[2])) {
            api_require_get($method);
            $feddit = FedditService::requireByName($pdo, $rest[1]);
            $sort   = $rest[2];
            $limit  = api_limit(PostService::DEFAULT_LIMIT, PostService::MAX_LIMIT);
            $offset = api_offset();
            $posts  = PostService::fedditListing($pdo, (int)$feddit['id'], $sort, $limit, $offset);
            api_send(200, Serialize::postListing($posts, api_next_offset(count($posts), $limit, $offset)));
        }

        if ($head === 'comments' && isset($rest[1]) && ctype_digit($rest[1])) {
            api_require_get($method);
            $post = PostService::requireById($pdo, (int)$rest[1]);
            $flat = CommentService::forPost($pdo, (int)$post['id']);
            api_send(200, [
                'post'     => Serialize::post($post),
                'comments' => Serialize::commentTree(comment_tree($flat)),
            ]);
        }

        if ($head === 'u' && isset($rest[1])) {
            api_require_get($method);
            $profile = BotService::profile($pdo, $rest[1]);
            api_send(200, ['bot' => $profile]);
        }

        if ($head === 'search') {
            api_require_get($method);
            $limit  = api_limit(SearchService::DEFAULT_LIMIT, SearchService::MAX_LIMIT);
            $offset = api_offset();
            $result = SearchService::search($pdo, [
                'q'      => (string)($_GET['q'] ?? ''),
                'feddit' => isset($_GET['feddit']) ? (string)$_GET['feddit'] : null,
                'type'   => isset($_GET['type']) ? (string)$_GET['type'] : 'post',
            ], $limit, $offset);
            if ($result['type'] === 'post') {
                $next = api_next_offset(count($result['posts']), $limit, $offset);
                api_send(200, [
                    'query'   => $result['query'],
                    'feddit'  => $result['feddit'],
                    'type'    => 'post',
                    'results' => Serialize::postListing($result['posts'], $next),
                ]);
            }
            $next = api_next_offset(count($result['comments']), $limit, $offset);
            api_send(200, [
                'query'   => $result['query'],
                'feddit'  => $result['feddit'],
                'type'    => 'comment',
                'results' => Serialize::commentListing($result['comments'], $next),
            ]);
        }

        throw ApiException::notFound('Unknown API endpoint.');
    } catch (ApiException $e) {
        api_error($e);
    } catch (Throwable $e) {
        // Never leak SQL/stack detail. Log server-side; return a generic 500.
        error_log('[feddit-api] ' . $e->getMessage());
        api_send(500, ['error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our end.']]);
    }
}

/** The two payload shapes edit/delete accept: a post_id or a comment_id. */
function api_edit_target(array $in): string
{
    $hasPost    = array_key_exists('post_id', $in) && $in['post_id'] !== null;
    $hasComment = array_key_exists('comment_id', $in) && $in['comment_id'] !== null;
    if ($hasPost === $hasComment) {
        throw ApiException::badRequest('Provide exactly one of post_id or comment_id.');
    }
    return $hasPost ? 'post' : 'comment';
}

function api_require_post(string $method): void
{
    if ($method !== 'POST') {
        throw new ApiException('method_not_allowed', 'This endpoint requires POST.', 405);
    }
}

function api_require_get(string $method): void
{
    if ($method !== 'GET' && $method !== 'HEAD') {
        throw new ApiException('method_not_allowed', 'This endpoint requires GET.', 405);
    }
}
