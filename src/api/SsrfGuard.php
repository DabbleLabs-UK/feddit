<?php
declare(strict_types=1);

require_once __DIR__ . '/ClientIp.php';

/**
 * SSRF defence for the link-preview fetcher.
 *
 * The URL a preview is fetched from is BOT-SUPPLIED, and this code runs on a box
 * that also hosts other DabbleLabs sites and sits next to a cloud metadata
 * endpoint (169.254.169.254). That makes the fetcher a textbook SSRF sink, so it
 * is treated as hostile input at every step:
 *
 *   - only http/https schemes are ever accepted;
 *   - the host is resolved and EVERY resolved address is checked against a
 *     blocklist of private, loopback, link-local, multicast and reserved ranges,
 *     for IPv4 AND IPv6 (including the IPv4-mapped / NAT64 / 6to4 IPv6 forms that
 *     are the classic way to smuggle 127.0.0.1 past a naive v4-only check);
 *   - if ANY resolved address is disallowed the whole URL is rejected, so a DNS
 *     answer that mixes one public and one private record cannot slip through;
 *   - the caller pins the connection to the exact IP validated here (see
 *     LinkPreviewService), closing the resolve-then-reconnect DNS-rebinding window;
 *   - this validation is re-run at EVERY redirect hop, not just the first URL.
 *
 * This class is deliberately pure and network-free (DNS aside) so the whole
 * boundary is unit-testable without a live target.
 */
final class SsrfGuard
{
    /** IPv4 ranges we refuse to fetch from (RFC 1918/6890 special-use + friends). */
    public const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this host"
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local (incl. 169.254.169.254 cloud metadata)
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved / future use
        '255.255.255.255/32', // limited broadcast
    ];

    /** IPv6 ranges we refuse to fetch from. */
    public const BLOCKED_V6 = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        '::ffff:0:0/96',    // IPv4-mapped (embedded v4 also checked separately)
        '64:ff9b::/96',     // NAT64 (embedded v4 also checked separately)
        '100::/64',         // discard-only
        '2001:db8::/32',    // documentation
        '2002::/16',        // 6to4 (embedded v4 also checked separately)
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    /**
     * True only for a routable public unicast address. Anything unparseable, or
     * in any blocked range, or an IPv6 form that EMBEDS a blocked IPv4 address,
     * is false. Recurses through the embedded v4 so ::ffff:127.0.0.1 and friends
     * are caught the same as 127.0.0.1.
     */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        $len = strlen($bin);
        if ($len === 4) {
            return !ClientIp::inAnyRange($ip, self::BLOCKED_V4);
        }
        if ($len === 16) {
            // If this v6 address embeds a v4 address (mapped/compat/NAT64/6to4),
            // that v4 must itself be public - else it is a smuggled internal IP.
            $embedded = self::embeddedV4($bin);
            if ($embedded !== null && !self::isPublicIp($embedded)) {
                return false;
            }
            return !ClientIp::inAnyRange($ip, self::BLOCKED_V6);
        }
        return false;
    }

    /**
     * The IPv4 address embedded in an IPv4-mapped / IPv4-compatible / NAT64 /
     * 6to4 IPv6 address, or null when there is none. $bin is a 16-byte packed v6.
     */
    private static function embeddedV4(string $bin): ?string
    {
        // 6to4: 2002:V4::/16 - the v4 is bytes 2..5.
        if ($bin[0] === "\x20" && $bin[1] === "\x02") {
            return @inet_ntop(substr($bin, 2, 4)) ?: null;
        }
        // NAT64 well-known prefix 64:ff9b::/96 - the v4 is the low 32 bits.
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") {
            return @inet_ntop(substr($bin, 12, 4)) ?: null;
        }
        // IPv4-mapped ::ffff:0:0/96 - the v4 is the low 32 bits.
        if (substr($bin, 0, 10) === str_repeat("\x00", 10) && $bin[10] === "\xff" && $bin[11] === "\xff") {
            return @inet_ntop(substr($bin, 12, 4)) ?: null;
        }
        // IPv4-compatible ::/96 (deprecated) - all-zero high 96 bits.
        if (substr($bin, 0, 12) === str_repeat("\x00", 12)) {
            return @inet_ntop(substr($bin, 12, 4)) ?: null;
        }
        return null;
    }

    /**
     * Validate a single URL (one hop) for fetching. Returns a descriptor:
     *   ['url','scheme','host','port','ip','ips']
     * where `ip` is the validated address to PIN the connection to, and `ips` is
     * every address the host resolved to (all of which passed validation).
     *
     * Throws LinkFetchError (terminal / 'blocked') on any non-http(s) scheme, an
     * unresolvable host, or a host that resolves to any disallowed address.
     */
    public static function validateUrl(string $url): array
    {
        $parts = @parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw LinkFetchError::blocked('URL is malformed or missing a scheme/host.');
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw LinkFetchError::blocked("Refused non-http(s) scheme: {$scheme}.");
        }

        $host = (string)$parts['host'];
        // parse_url keeps IPv6 literals bracketed (e.g. "[::1]"); strip for inet_pton.
        if ($host !== '' && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw LinkFetchError::blocked('URL has an empty host.');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

        $ips = self::resolveHost($host);
        if ($ips === []) {
            throw LinkFetchError::failed("Could not resolve host: {$host}.");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw LinkFetchError::blocked(
                    "Host {$host} resolves to a non-public address ({$ip}) - refused."
                );
            }
        }

        return [
            'url'    => $url,
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => $port,
            'ip'     => $ips[0],   // pin the connection to this validated address
            'ips'    => $ips,
        ];
    }

    /**
     * Resolve a host to every A and AAAA address. A literal IP resolves to itself.
     * Network-touching (DNS), kept small and wrapped so a lookup failure is a
     * clean empty result, never a warning or fatal.
     *
     * @return string[]
     */
    public static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];   // already a literal address
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record($host, DNS_AAAA);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = (string)$r['ipv6'];
                    }
                }
            }
        }
        // Only keep things that actually parse as an IP.
        $ips = array_values(array_unique(array_filter($ips, static function ($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        })));
        return $ips;
    }
}
