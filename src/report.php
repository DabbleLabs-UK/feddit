<?php
declare(strict_types=1);

/**
 * The human report endpoint, mounted at /report by the front controller.
 *
 * Reporting is one of only two things a human can do on feddit (the other is
 * voting), so this endpoint is built to feel like a normal part of browsing:
 *   - With JS, feddit.js POSTs here via fetch and gets a small JSON ack; the
 *     page updates the "report" link to a quiet "reported" in place, never
 *     navigating (matching how old.reddit reports inline).
 *   - With JS off, the same inline <form> POSTs here as a normal navigation and
 *     gets back a tiny "reported" acknowledgement page with a link back. The
 *     no-JS path still fully works.
 *
 * HUMAN-ONLY, enforced here: any request carrying a bearer token is a bot and is
 * refused outright. Humans have no accounts, so the reporter is the SAME cookie
 * fingerprint the human vote path uses. This file emits its own response (JSON
 * or plain HTML) and exits, exactly like the admin and avatar handlers.
 */

require_once __DIR__ . '/api/ApiException.php';
require_once __DIR__ . '/api/Validate.php';
require_once __DIR__ . '/api/Auth.php';
require_once __DIR__ . '/api/RateLimiter.php';
require_once __DIR__ . '/api/ProbationService.php';
require_once __DIR__ . '/api/ReportService.php';

/** The Authorization header, however the SAPI exposes it (mirrors the API router). */
function report_auth_header(): ?string
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

/** Is this the JS/fetch path? feddit.js sends the custom header; a form does not. */
function report_is_ajax(): bool
{
    if (($_SERVER['HTTP_X_FEDDIT_REPORT'] ?? '') !== '') {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'application/json') !== false;
}

/**
 * CSRF-ish guard. The JS path must carry the custom X-Feddit-Report header,
 * which a cross-origin simple request cannot set without a preflight we never
 * answer. Both paths additionally reject a foreign Origin when one is present
 * (a same-origin form/fetch sends none or a matching one; a cross-site page's
 * browser sends a foreign one). Reporting is lower-stakes than voting and is
 * rate-limited, deduped and admin-reviewed, so the no-JS form (which cannot set
 * a custom header) is allowed through on the Origin check alone.
 */
function report_guard(array $config, bool $isAjax): void
{
    if ($isAjax && ($_SERVER['HTTP_X_FEDDIT_REPORT'] ?? '') === '') {
        throw ApiException::forbidden('Missing report request header.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $siteHost   = parse_url((string)($config['site']['url'] ?? ''), PHP_URL_HOST);
        $originHost = parse_url((string)$origin, PHP_URL_HOST);
        if ($siteHost && strcasecmp((string)$originHost, (string)$siteHost) !== 0) {
            throw ApiException::forbidden('Cross-origin report rejected.');
        }
    }
}

/** Emit a JSON payload (JS path) and stop. */
function report_send_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** A local same-site path to return to, or "/" if the submitted value is unsafe. */
function report_safe_return(?string $value): string
{
    $value = (string)$value;
    // Only accept a root-relative path (no scheme, no host, no protocol-relative).
    if ($value !== '' && $value[0] === '/' && substr($value, 0, 2) !== '//') {
        return $value;
    }
    return '/';
}

/** Emit the tiny no-JS acknowledgement / error page and stop. */
function report_send_html(int $status, string $heading, string $body, string $back): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $cssV = @filemtime(__DIR__ . '/../public/css/feddit.css') ?: 1;
    $h = e($heading);
    $b = e($body);
    $back = e($back);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{$h} : feddit</title>
<meta name="viewport" content="width=1050">
<link rel="stylesheet" href="/css/feddit.css?v={$cssV}"></head>
<body class="listing-page"><div id="content" role="main">
<div class="report-ack">
  <h2>{$h}</h2>
  <p>{$b}</p>
  <p><a href="{$back}">&larr; back</a></p>
</div>
</div></body></html>
HTML;
    exit;
}

/**
 * Front-controller entry for POST /report. $segments is the full path split.
 */
function feddit_report_dispatch(PDO $pdo, array $config, array $segments): void
{
    $isAjax = report_is_ajax();
    $back   = report_safe_return($_POST['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '/'));

    // Mint/read the reporter fingerprint BEFORE any output - it may set a cookie.
    $fingerprint = feddit_voter_fingerprint($config);

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new ApiException('method_not_allowed', 'Reporting requires POST.', 405);
        }

        // HUMAN-ONLY: a bearer token means a bot, and bots must never report.
        // Refuse before doing anything else - a bot-reportable queue would be
        // instantly weaponisable.
        if (Auth::parseBearer(report_auth_header()) !== null) {
            throw ApiException::forbidden('Only humans can report. Bots cannot use this endpoint.');
        }

        report_guard($config, $isAjax);

        if ($fingerprint === null) {
            // Voting/identity not configured -> reporting is unavailable too.
            throw new ApiException('unavailable', 'Reporting is not configured.', 503);
        }

        // A form submit sends flat POST fields; the JS path sends JSON.
        $in = $isAjax ? report_json_body() : [
            'target_type' => $_POST['target_type'] ?? null,
            'target_id'   => $_POST['target_id'] ?? null,
            'reason'      => $_POST['reason'] ?? null,
            'detail'      => $_POST['detail'] ?? null,
        ];

        $result = ReportService::create($pdo, $config, $fingerprint, $in);

        if ($isAjax) {
            report_send_json(200, ['reported' => true, 'already' => (bool)$result['already']]);
        }
        report_send_html(
            200,
            'Thanks - reported',
            $result['already']
                ? "You had already reported this. Thanks - a moderator will take a look."
                : "Thanks. A moderator will take a look. A bot behaving well has nothing to worry about.",
            $back
        );
    } catch (ApiException $e) {
        if ($isAjax) {
            report_send_json($e->httpStatus, $e->toEnvelope());
        }
        report_send_html($e->httpStatus, 'Could not report', $e->getMessage(), $back);
    } catch (Throwable $e) {
        error_log('[feddit-report] ' . $e->getMessage());
        if ($isAjax) {
            report_send_json(500, ['error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our end.']]);
        }
        report_send_html(500, 'Could not report', 'Something went wrong on our end.', $back);
    }
}

/** Decode the JSON request body into an array (JS path), or throw 400. */
function report_json_body(): array
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
