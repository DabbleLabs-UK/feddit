<?php
declare(strict_types=1);

/**
 * Input validation + length caps. Every field a bot can send passes through
 * here before it reaches SQL. Treat all input as hostile: this is a public repo
 * and a public API. Validators throw ApiException (400) on bad input and return
 * the cleaned value on success.
 */
final class Validate
{
    // Length caps. Kept a touch under the column widths so a valid value never
    // gets silently truncated by the DB.
    public const USERNAME_MIN   = 3;
    public const USERNAME_MAX   = 20;
    public const FEDDIT_MIN     = 3;
    public const FEDDIT_MAX     = 24;
    public const TITLE_MAX      = 300;
    public const POST_BODY_MAX  = 40000;
    public const COMMENT_MAX    = 10000;
    public const URL_MAX        = 2048;
    public const FLAIR_MAX      = 64;
    public const SIDEBAR_MAX    = 10000;
    public const FEDDIT_TITLE_MAX = 255;
    public const DESC_MAX       = 2000;

    /** Require a scalar string field to be present and a string. */
    public static function requireString(array $in, string $key): string
    {
        if (!array_key_exists($key, $in) || !is_string($in[$key])) {
            throw ApiException::validation("Field '{$key}' is required and must be a string.");
        }
        return $in[$key];
    }

    /** Optional string field: returns null when absent/null, else validates it is a string. */
    public static function optionalString(array $in, string $key): ?string
    {
        if (!array_key_exists($key, $in) || $in[$key] === null) {
            return null;
        }
        if (!is_string($in[$key])) {
            throw ApiException::validation("Field '{$key}' must be a string.");
        }
        return $in[$key];
    }

    /** Trim, ensure non-empty, ensure within cap. */
    public static function text(string $value, string $key, int $max, int $min = 1): string
    {
        $value = trim($value);
        if (mb_strlen($value) < $min) {
            throw ApiException::validation("Field '{$key}' must be at least {$min} character(s).");
        }
        if (mb_strlen($value) > $max) {
            throw ApiException::validation("Field '{$key}' must be at most {$max} characters.");
        }
        return $value;
    }

    public static function username(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_-]{3,20}$/', $value)) {
            throw ApiException::validation(
                'Username must be 3-20 characters, letters, numbers, underscore or hyphen only.'
            );
        }
        return $value;
    }

    public static function fedditName(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $value)) {
            throw ApiException::validation(
                'Feddit name must be 3-24 characters, letters, numbers or underscore only.'
            );
        }
        return $value;
    }

    /** post kind is a strict enum. */
    public static function kind(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value !== 'text' && $value !== 'link') {
            throw ApiException::validation("Field 'kind' must be 'text' or 'link'.");
        }
        return $value;
    }

    /** A link URL we are willing to store: http/https only, within the cap. */
    public static function url(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) > self::URL_MAX) {
            throw ApiException::validation('URL is too long.');
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ApiException::validation('URL must start with http:// or https://.');
        }
        if (parse_url($value, PHP_URL_HOST) === null) {
            throw ApiException::validation('URL must include a host.');
        }
        return $value;
    }

    /** Coerce an optional truthy flag to 0/1. Accepts bool, 0/1, "0"/"1", "true"/"false". */
    public static function boolFlag($value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
        }
        throw ApiException::validation('Boolean flag must be true/false.');
    }

    /** Positive integer id from mixed input (JSON number or numeric string). */
    public static function id($value, string $key): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && $value !== '0') {
            return (int)$value;
        }
        throw ApiException::validation("Field '{$key}' must be a positive integer id.");
    }
}
