<?php
declare(strict_types=1);

require_once __DIR__ . '/SsrfGuard.php';
require_once __DIR__ . '/LinkFetchError.php';

/**
 * Bounded reader for a curl transfer: appends body chunks into a buffer and
 * ABORTS the transfer the moment it has enough - either the </head> terminator
 * (for HTML head parsing) or the hard byte cap. Returning fewer bytes than curl
 * handed us tells libcurl to stop, so we never read a whole multi-megabyte page
 * or image into memory just to throw most of it away. Kept as its own class so
 * the size-cap / head-stop logic is unit-testable without a socket.
 */
final class LinkBodyReader
{
    public string $buf = '';
    public bool $capped = false;    // hit the byte cap
    public bool $headDone = false;  // saw </head> (stop_at_head only)

    private int $maxBytes;
    private bool $stopAtHead;

    public function __construct(int $maxBytes, bool $stopAtHead)
    {
        $this->maxBytes   = max(1, $maxBytes);
        $this->stopAtHead = $stopAtHead;
    }

    /** curl WRITEFUNCTION: return strlen($data) to continue, less to abort. */
    public function write($ch, string $data): int
    {
        $this->buf .= $data;
        if ($this->stopAtHead && stripos($this->buf, '</head>') !== false) {
            $this->headDone = true;
            return 0;   // we have the whole <head>; stop the download here
        }
        if (strlen($this->buf) >= $this->maxBytes) {
            $this->capped = true;
            // Keep at most the cap, so a stop_at_head buffer stays bounded.
            if (strlen($this->buf) > $this->maxBytes) {
                $this->buf = substr($this->buf, 0, $this->maxBytes);
            }
            return 0;   // over the cap; stop
        }
        return strlen($data);
    }

    /** True when we stopped the transfer on purpose (head found or cap hit). */
    public function stoppedDeliberately(): bool
    {
        return $this->headDone || $this->capped;
    }
}

/**
 * Fetches link-preview metadata for a kind='link' post - the target page's
 * <head> ONLY (never body text, so paywalled articles are handled ethically by
 * construction) plus its og:image, both through the SSRF-hardened, IP-pinned
 * path. This class is the network side; SsrfGuard is the pure validation side.
 *
 * Every fetch:
 *   - validates the URL (scheme + resolved IPs) via SsrfGuard,
 *   - pins curl to the validated IP with CURLOPT_RESOLVE so libcurl connects to
 *     exactly the address we checked (Host/SNI preserved) - this closes the
 *     resolve-then-reconnect DNS-rebinding window,
 *   - follows redirects MANUALLY, re-validating every hop (a public URL that
 *     302s to 169.254.169.254 or 127.0.0.1 is refused at the hop),
 *   - caps the response body and aborts once <head> is read or the cap is hit,
 *   - honours robots.txt and sends a real identifying User-Agent.
 */
final class LinkPreviewService
{
    /** Cap on the HTML we read while hunting the <head> (og tags live near the top). */
    public const HEAD_MAX_BYTES = 262144;      // 256 KB

    /** Cap on an og:image download before we re-encode it to a thumbnail. */
    public const IMAGE_MAX_BYTES = 5242880;    // 5 MB

    /** Cap on a robots.txt we read to decide whether we may fetch. */
    private const ROBOTS_MAX_BYTES = 65536;    // 64 KB

    public const TIMEOUT_SECONDS  = 5;
    public const MAX_REDIRECTS    = 3;

    /** The robots.txt product token we answer to (matched case-insensitively). */
    public const ROBOTS_AGENT = 'feddit';

    /**
     * A real, identifying User-Agent naming Feddit with a contact URL, so a
     * publisher can see who is fetching and reach us. NOT a spoofed browser UA:
     * this fetch is a declared, well-behaved preview crawler.
     */
    public static function userAgent(array $config): string
    {
        $site = (string)($config['site']['url'] ?? 'https://feddit.dabblelabs.uk');
        $site = rtrim($site, '/');
        return 'FedditLinkPreview/1.0 (+' . $site . '/docs; link-preview bot)';
    }

    // -- high-level: head + image ------------------------------------------

    /**
     * Fetch and parse the target's <head>. Returns:
     *   ['final_url' => string, 'meta' => ['title','description','site_name','image']]
     * Throws LinkFetchError on refusal/failure.
     */
    public static function fetchHead(string $url, array $config): array
    {
        if (!self::robotsAllows($url, $config)) {
            throw LinkFetchError::blocked('robots.txt disallows fetching this URL.');
        }
        $res = self::rawFetch($url, $config, [
            'max_bytes'    => self::HEAD_MAX_BYTES,
            'stop_at_head' => true,
            'accept'       => 'text/html,application/xhtml+xml',
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw LinkFetchError::failed('Upstream returned HTTP ' . $res['status'] . '.');
        }
        $meta = self::parseHead($res['body']);
        // Resolve a relative/protocol-relative og:image against the final URL.
        if ($meta['image'] !== null) {
            $meta['image'] = self::absolutize($res['final_url'], $meta['image']);
        }
        return ['final_url' => $res['final_url'], 'meta' => $meta];
    }

    /**
     * Fetch an og:image through the same SSRF-validated, IP-pinned, size-capped
     * path and return its raw bytes. Throws LinkFetchError on refusal/failure or
     * if the download exceeds the cap. The caller is responsible for proving the
     * bytes are genuinely an image (by inspecting them) and re-encoding.
     */
    public static function fetchImageBytes(string $url, array $config): string
    {
        if (!self::robotsAllows($url, $config)) {
            throw LinkFetchError::noImage('robots.txt disallows fetching the image.');
        }
        $res = self::rawFetch($url, $config, [
            'max_bytes'    => self::IMAGE_MAX_BYTES,
            'stop_at_head' => false,
            'accept'       => 'image/*',
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw LinkFetchError::noImage('Image request returned HTTP ' . $res['status'] . '.');
        }
        if ($res['capped']) {
            throw LinkFetchError::noImage('Image exceeds the ' . self::IMAGE_MAX_BYTES . '-byte cap.');
        }
        if ($res['body'] === '') {
            throw LinkFetchError::noImage('Image response was empty.');
        }
        return $res['body'];
    }

    // -- the SSRF-hardened fetch core --------------------------------------

    /**
     * Fetch a URL with the full SSRF defence: validate + pin + manual redirect
     * revalidation + size cap. No robots check here (the callers do that once at
     * the top, and robots.txt itself must be fetchable without recursing).
     *
     * @return array{status:int,body:string,final_url:string,content_type:string,capped:bool}
     */
    public static function rawFetch(string $url, array $config, array $opts): array
    {
        if (!function_exists('curl_init')) {
            throw LinkFetchError::failed('curl is not available on the server.');
        }
        $maxBytes   = (int)($opts['max_bytes'] ?? self::HEAD_MAX_BYTES);
        $stopAtHead = (bool)($opts['stop_at_head'] ?? false);
        $accept     = (string)($opts['accept'] ?? '*/*');
        $ua         = self::userAgent($config);

        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // Re-validate EVERY hop - the redirect target is as untrusted as the
            // first URL. Throws (terminal 'blocked') on a private/blocked address.
            $ctx = SsrfGuard::validateUrl($current);

            $reader = new LinkBodyReader($maxBytes, $stopAtHead);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL             => $ctx['url'],
                // Pin the connection to the exact validated IP (Host/SNI intact).
                CURLOPT_RESOLVE         => [$ctx['host'] . ':' . $ctx['port'] . ':' . $ctx['ip']],
                CURLOPT_FOLLOWLOCATION  => false,  // we follow by hand to revalidate
                CURLOPT_TIMEOUT         => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT  => self::TIMEOUT_SECONDS,
                CURLOPT_USERAGENT       => $ua,
                CURLOPT_HTTPHEADER      => ['Accept: ' . $accept],
                CURLOPT_ACCEPT_ENCODING => '',     // advertise + transparently decode gzip
                // Never let libcurl be talked into a non-http(s) scheme, even on a redirect.
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_WRITEFUNCTION   => [$reader, 'write'],
            ]);
            $ok     = curl_exec($ch);
            $errno  = curl_errno($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $location = self::locationFrom($ch, $reader->buf);
            curl_close($ch);

            // A write-callback abort (errno 23/42) is OUR deliberate stop, not a
            // failure. Any other curl error is a real transport failure -> retry.
            if ($ok === false && !$reader->stoppedDeliberately()
                && $errno !== CURLE_WRITE_ERROR && $errno !== 42 /* ABORTED_BY_CALLBACK */) {
                throw LinkFetchError::failed('curl error ' . $errno . ' fetching ' . $ctx['host'] . '.');
            }

            if ($status >= 300 && $status < 400 && $location !== null) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw LinkFetchError::failed('Too many redirects.');
                }
                $current = self::absolutize($current, $location);
                continue;
            }

            return [
                'status'       => $status,
                'body'         => $reader->buf,
                'final_url'    => $current,
                'content_type' => $ctype,
                'capped'       => $reader->capped,
            ];
        }
        throw LinkFetchError::failed('Too many redirects.');
    }

    /**
     * The Location header of a 3xx. curl with a WRITEFUNCTION still exposes the
     * effective redirect via CURLINFO_REDIRECT_URL when it parsed one; fall back
     * to scraping the header block if needed. We follow manually, so we read it
     * rather than letting curl chase it.
     */
    private static function locationFrom($ch, string $body): ?string
    {
        $redir = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if (is_string($redir) && $redir !== '') {
            return $redir;
        }
        return null;
    }

    // -- robots.txt --------------------------------------------------------

    /**
     * Whether robots.txt permits us to fetch $url. Fetched through the same
     * SSRF-validated path. Default-allow: a missing/unreadable robots.txt, or a
     * non-2xx, means no restriction (standard crawler behaviour). We honour the
     * most specific matching Disallow for our agent token, then '*'.
     */
    public static function robotsAllows(string $url, array $config): bool
    {
        $parts = @parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return true;   // malformed - SsrfGuard will reject it anyway
        }
        $scheme = strtolower((string)$parts['scheme']);
        $host   = (string)$parts['host'];
        $port   = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $robotsUrl = $scheme . '://' . $host . $port . '/robots.txt';
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        try {
            $res = self::rawFetch($robotsUrl, $config, [
                'max_bytes'    => self::ROBOTS_MAX_BYTES,
                'stop_at_head' => false,
                'accept'       => 'text/plain',
            ]);
        } catch (LinkFetchError $e) {
            // Can't fetch robots (blocked/failed) - if it was BLOCKED (SSRF), the
            // real fetch will be blocked too; treat as allowed here and let the
            // real fetch make the refusal. A transient failure -> default-allow.
            return true;
        }
        if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
            return true;   // no usable robots.txt -> allowed
        }
        return self::robotsPermits($res['body'], $path);
    }

    /**
     * Parse robots.txt content and decide if $path is allowed for our agent.
     * Picks the group for our token if present, else the '*' group. Within the
     * group, the longest matching Allow/Disallow rule wins (a Disallow blocks,
     * an Allow or an empty Disallow permits). Pure - unit-testable.
     */
    public static function robotsPermits(string $robots, string $path): bool
    {
        $lines = preg_split('/\r\n|\r|\n/', $robots);
        // Collect rules per user-agent group.
        $groups = [];          // agent(lower) => [ [type,path], ... ]
        $currentAgents = [];
        $sawRuleForCurrent = false;
        foreach ($lines as $raw) {
            $line = trim(preg_replace('/#.*$/', '', $raw));
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);
            if ($field === 'user-agent') {
                // A new user-agent line after rules starts a fresh group block.
                if ($sawRuleForCurrent) {
                    $currentAgents = [];
                    $sawRuleForCurrent = false;
                }
                $currentAgents[] = strtolower($value);
                if (!isset($groups[strtolower($value)])) {
                    $groups[strtolower($value)] = [];
                }
            } elseif ($field === 'allow' || $field === 'disallow') {
                $sawRuleForCurrent = true;
                foreach ($currentAgents as $a) {
                    $groups[$a][] = [$field, $value];
                }
            }
        }

        $rules = $groups[self::ROBOTS_AGENT] ?? $groups['*'] ?? [];
        if ($rules === []) {
            return true;
        }
        $bestLen  = -1;
        $bestType = 'allow';
        foreach ($rules as [$type, $rulePath]) {
            if ($type === 'disallow' && $rulePath === '') {
                continue;   // "Disallow:" (empty) = allow everything, matches nothing
            }
            if (self::robotsPathMatch($rulePath, $path)) {
                $len = strlen($rulePath);
                if ($len > $bestLen) {
                    $bestLen  = $len;
                    $bestType = $type;
                }
            }
        }
        return $bestType !== 'disallow';
    }

    /** Prefix match with robots '*' wildcard and '$' end-anchor support. */
    private static function robotsPathMatch(string $rule, string $path): bool
    {
        if ($rule === '') {
            return false;
        }
        // Translate the robots pattern into a regex: * => .*, trailing $ anchors.
        $anchorEnd = false;
        if (substr($rule, -1) === '$') {
            $anchorEnd = true;
            $rule = substr($rule, 0, -1);
        }
        $parts = explode('*', $rule);
        $regex = '#^' . implode('.*', array_map('preg_quote', $parts, array_fill(0, count($parts), '#')));
        $regex .= $anchorEnd ? '$#' : '#';
        return preg_match($regex, $path) === 1;
    }

    // -- <head> parsing ----------------------------------------------------

    /**
     * Pull the preview fields out of a page's <head> HTML: og:* first, then the
     * twitter:* equivalents, then <title> / <meta name="description">. METADATA
     * ONLY - this never looks at or returns body text.
     *
     * @return array{title:?string,description:?string,site_name:?string,image:?string}
     */
    public static function parseHead(string $html): array
    {
        // Only ever look at the <head> so we cannot accidentally scrape body copy.
        if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $hm)) {
            $head = $hm[1];
        } else {
            // No closing </head> seen (truncated): use what we have, up to <body>.
            $head = preg_split('/<body\b/i', $html)[0] ?? $html;
        }

        $meta = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $head, $tags)) {
            foreach ($tags[0] as $tag) {
                $key = self::attr($tag, 'property') ?? self::attr($tag, 'name');
                if ($key === null) {
                    continue;
                }
                $content = self::attr($tag, 'content');
                if ($content === null) {
                    continue;
                }
                $key = strtolower(trim($key));
                if (!isset($meta[$key])) {   // first occurrence wins
                    $meta[$key] = html_entity_decode(trim($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        $title = null;
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $head, $tm)) {
            $title = html_entity_decode(trim(preg_replace('/\s+/', ' ', $tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '') {
                $title = null;
            }
        }

        $pick = static function (array $m, array $keys) {
            foreach ($keys as $k) {
                if (isset($m[$k]) && $m[$k] !== '') {
                    return $m[$k];
                }
            }
            return null;
        };

        return [
            'title'       => self::cap($pick($meta, ['og:title', 'twitter:title']) ?? $title, 512),
            'description' => self::cap($pick($meta, ['og:description', 'twitter:description', 'description']), 1024),
            'site_name'   => self::cap($pick($meta, ['og:site_name', 'application-name']), 255),
            'image'       => $pick($meta, ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src']),
        ];
    }

    /** Read one HTML attribute's value from a single tag string, or null. */
    private static function attr(string $tag, string $name): ?string
    {
        if (!preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $m)) {
            return null;
        }
        $whole = $m[1];
        if ($whole !== '' && $whole[0] === '"') {
            return $m[2];                 // double-quoted (may be empty)
        }
        if ($whole !== '' && $whole[0] === "'") {
            return $m[3];                 // single-quoted (may be empty)
        }
        return $m[4] ?? null;             // unquoted
    }

    /** Trim to a max length (matching the DB column caps), null-safe. */
    private static function cap(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    // -- URL joining -------------------------------------------------------

    /** Resolve a possibly-relative reference (Location / og:image) against a base. */
    public static function absolutize(string $base, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $ref)) {
            return $ref;   // already absolute
        }
        $b = @parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        $host   = $b['host'] ?? '';
        $port   = isset($b['port']) ? ':' . (int)$b['port'] : '';
        $authority = $scheme . '://' . $host . $port;

        if (strpos($ref, '//') === 0) {
            return $scheme . ':' . $ref;   // protocol-relative
        }
        if ($ref !== '' && $ref[0] === '/') {
            return $authority . $ref;      // root-relative
        }
        // Relative to the base path's directory.
        $path = $b['path'] ?? '/';
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);
        return $authority . $dir . $ref;
    }
}
