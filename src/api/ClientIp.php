<?php
declare(strict_types=1);

/**
 * Resolve the REAL client IP behind a trusted reverse proxy, safely.
 *
 * Feddit sits behind Cloudflare, so the socket peer (REMOTE_ADDR) is a Cloudflare
 * edge IP shared by every visitor, and the visitor's own address arrives in the
 * CF-Connecting-IP header. That header is trivially forgeable by anyone talking
 * to the origin directly, so trusting it blindly would be WORSE than no limit at
 * all - it would look like protection while letting a spammer rotate the header
 * for a fresh bucket on every request.
 *
 * The rule here is therefore: only believe CF-Connecting-IP when the request
 * genuinely arrived from a Cloudflare (trusted-proxy) IP range. Otherwise the
 * header is ignored and the socket peer is the client.
 *
 * We never store a raw IP: resolve() yields the address, hash() turns it into a
 * salted SHA-256 so what lands in the DB is a pseudonymous fingerprint, not a PII
 * address.
 */
final class ClientIp
{
    /**
     * Cloudflare's published edge ranges (www.cloudflare.com/ips-v4 + /ips-v6).
     * These change very rarely; override via config['cloudflare']['trusted_ranges']
     * if they ever do, without a code change.
     */
    public const CLOUDFLARE_RANGES = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * The real client IP for this request, or null when it cannot be determined.
     *
     * null happens ONLY when the peer is a trusted proxy but no valid
     * CF-Connecting-IP is present: we deliberately return null rather than the
     * shared edge IP, so the caller never rate-limits the whole of Cloudflare as
     * a single "client". Behind Cloudflare the header is always present in
     * practice, so this is a misconfiguration guard, not the normal path.
     *
     * @param array $server the $_SERVER superglobal (passed in to stay testable)
     */
    public static function resolve(array $server, array $config): ?string
    {
        $remote = $server['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (self::inAnyRange($remote, self::trustedRanges($config))) {
            // Trusted proxy in front: believe its forwarded client IP, and ONLY it.
            $cf = $server['HTTP_CF_CONNECTING_IP'] ?? '';
            if (is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
                return $cf;
            }
            return null; // can't attribute; never fall back to the shared edge IP
        }

        // Direct connection (no trusted proxy): the socket peer IS the client.
        // Any CF-Connecting-IP here is attacker-supplied and must be ignored.
        return $remote;
    }

    /**
     * The salted SHA-256 of the resolved client IP, or null when unresolvable.
     * This is what gets stored / counted - a raw IP never touches the DB.
     */
    public static function hashedClientIp(array $server, array $config): ?string
    {
        $ip = self::resolve($server, $config);
        return $ip === null ? null : self::hash($ip, $config);
    }

    /** Salted hash of a single IP. Salt: registration.ip_salt, else vote_secret. */
    public static function hash(string $ip, array $config): string
    {
        $salt = (string)($config['registration']['ip_salt'] ?? '');
        if ($salt === '') {
            $salt = (string)($config['vote_secret'] ?? '');
        }
        return hash('sha256', 'feddit-ip-v1|' . $salt . '|' . $ip);
    }

    /** The CIDR ranges we trust CF-Connecting-IP from (config override or CF list). */
    private static function trustedRanges(array $config): array
    {
        $cfg = $config['cloudflare']['trusted_ranges'] ?? null;
        if (is_array($cfg) && $cfg !== []) {
            return $cfg;
        }
        return self::CLOUDFLARE_RANGES;
    }

    /** True if $ip falls inside any CIDR in $ranges (IPv4 and IPv6 both handled). */
    public static function inAnyRange(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $cidr) {
            if (self::cidrMatch($bin, (string)$cidr)) {
                return true;
            }
        }
        return false;
    }

    /** Match a packed-binary address against a "subnet/prefix" (or a bare IP). */
    private static function cidrMatch(string $bin, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            $net = @inet_pton($cidr);
            return $net !== false && $net === $bin;
        }
        [$subnet, $prefix] = explode('/', $cidr, 2);
        $netBin = @inet_pton($subnet);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false; // malformed, or a v4/v6 family mismatch
        }
        $prefix = (int)$prefix;
        $fullBytes = intdiv($prefix, 8);
        $remBits   = $prefix % 8;
        if ($fullBytes > 0 && strncmp($bin, $netBin, $fullBytes) !== 0) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remBits)) & 0xff;
        return (ord($bin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }
}
