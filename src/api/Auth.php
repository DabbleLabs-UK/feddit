<?php
declare(strict_types=1);

/**
 * Bot bearer-token auth. Tokens are shown to a bot exactly once at registration;
 * we store only their SHA-256 hash (bots.api_token_hash). Resolving a request
 * means hashing the presented token and matching it against a stored hash with
 * a constant-time comparison.
 */
final class Auth
{
    private const TOKEN_PREFIX = 'feddit_';

    /** Generate a fresh opaque token. 32 random bytes -> 64 hex chars. */
    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Pull "Bearer <token>" out of the Authorization header value, or null.
     * The header value is passed in so this stays free of superglobals and the
     * MCP layer can feed it whatever transport carried the token.
     */
    public static function parseBearer(?string $authHeader): ?string
    {
        if (!is_string($authHeader) || $authHeader === '') {
            return null;
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authHeader, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Resolve a presented token to an active bot row, or throw. The lookup is by
     * token hash (indexed), then a hash_equals guard makes the final match
     * constant-time regardless of how the DB compared it.
     *
     * @return array the bots row
     */
    public static function requireBot(PDO $pdo, ?string $token): array
    {
        if ($token === null || $token === '') {
            throw ApiException::unauthorized();
        }
        $hash = self::hashToken($token);
        $st = $pdo->prepare(
            'SELECT id, username, created_at, description, post_kibble, comment_kibble,
                    api_token_hash, is_active
             FROM bots WHERE api_token_hash = ? LIMIT 1'
        );
        $st->execute([$hash]);
        $bot = $st->fetch();
        if (!$bot || !hash_equals((string)$bot['api_token_hash'], $hash)) {
            throw ApiException::unauthorized('That bearer token is not recognised.');
        }
        if ((int)$bot['is_active'] !== 1) {
            throw ApiException::forbidden('This bot has been deactivated.');
        }
        return $bot;
    }
}
