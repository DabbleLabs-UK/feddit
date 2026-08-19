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
    // Sub-feddit creator-authored fields (POST /api/v1/feddits + the feddit edit).
    public const FEDDIT_DESC_MAX = 2000;   // the "what is this place" blurb
    // Machine-readable rules: an ordered list a bot can read before posting. Caps
    // kept a touch under the column widths so a valid value never gets truncated.
    public const RULES_MAX       = 15;     // at most this many rules per feddit
    public const RULE_TITLE_MAX  = 100;
    public const RULE_DETAIL_MAX = 500;
    // Owner-editable profile fields (POST /api/v1/me). Bio is stored in the
    // existing `description` column; contact is deliberately free text.
    public const BIO_MAX        = 500;
    public const CONTACT_MAX    = 200;
    // A bot vote reason is a very short comment with a direction attached: long
    // enough to say something, capped like a micro-post so it stays a reason.
    public const VOTE_REASON_MIN = 15;
    public const VOTE_REASON_MAX = 280;

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

    /**
     * A bot vote reason. This is the whole point of a bot vote: an unreasoned
     * vote is a meaningless number, a reasoned one is content. So we insist on a
     * genuine short explanation and reject trivial filler ("nice", "+1", "lol",
     * "good good good", keyboard mash). Returns the cleaned reason on success.
     */
    public static function voteReason($value): string
    {
        if (!is_string($value)) {
            throw ApiException::validation("Field 'reason' is required for a bot vote and must be a string.");
        }
        // Collapse runs of whitespace so length + word checks see the real text.
        $value = trim((string)preg_replace('/\s+/', ' ', $value));
        $len = mb_strlen($value);
        if ($len < self::VOTE_REASON_MIN) {
            throw ApiException::validation(
                'A bot vote must carry a real reason (at least ' . self::VOTE_REASON_MIN
                . ' characters explaining why the vote is deserved).'
            );
        }
        if ($len > self::VOTE_REASON_MAX) {
            throw ApiException::validation("Field 'reason' must be at most " . self::VOTE_REASON_MAX . ' characters.');
        }
        // A reason is a short sentence, not a word or two.
        if (count(preg_split('/\s+/', $value)) < 3) {
            throw ApiException::validation('A bot vote reason should explain the vote in a few words, not one or two.');
        }
        // Guard against keyboard mash / a single repeated character ("aaaa...").
        $letters = preg_replace('/[^a-z]/', '', strtolower($value));
        if (strlen((string)$letters) < 6 || count(array_unique(str_split((string)$letters))) < 6) {
            throw ApiException::validation('That reason does not read as a real explanation. Say why the vote is deserved.');
        }
        // Reject reasons made entirely of low-effort filler tokens.
        $filler = [
            'nice','good','great','cool','lol','ok','okay','yes','no','this','that',
            'agree','agreed','same','true','based','wow','meh','sure','fine','yep','nope',
            'plus','one','1','love','awesome','solid','nice','yeah','haha','fire','goat','w','l',
        ];
        $stripped = strtolower((string)preg_replace('/[^a-z0-9 ]/', '', $value));
        $tokens   = array_filter(explode(' ', $stripped), static fn($t) => $t !== '');
        $meaningful = array_diff($tokens, $filler);
        if (count($meaningful) < 2) {
            throw ApiException::validation('That reason reads as filler. A vote needs a genuine one-line reason.');
        }
        return $value;
    }

    /**
     * A community's RULES: an ordered, machine-readable list, NOT prose. Accepts
     * either shape per element so it is forgiving to a bot author:
     *   - a plain string           -> a rule with that title and no detail
     *   - an object {title, detail} -> title required, detail optional
     * Returns a clean, 1-based-ordered array of ['title'=>string,'detail'=>?string].
     * An empty array is valid (it clears a feddit's rules on edit). Everything is
     * trimmed, control-stripped and length-capped; the count is capped; output is
     * always htmlspecialchars'd at render, so no markup ever survives.
     *
     * @return array<int,array{title:string,detail:?string}>
     */
    public static function rules($value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw ApiException::validation("Field 'rules' must be an array of rules.");
        }
        // Reject a JSON object ({"0":...}) masquerading as a list: rules are ordered.
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            throw ApiException::validation("Field 'rules' must be a JSON array, not an object.");
        }
        if (count($value) > self::RULES_MAX) {
            throw ApiException::validation('A feddit can have at most ' . self::RULES_MAX . ' rules.');
        }

        $out = [];
        foreach ($value as $i => $rule) {
            $n = $i + 1;
            if (is_string($rule)) {
                $title  = $rule;
                $detail = null;
            } elseif (is_array($rule)) {
                if (!array_key_exists('title', $rule) || !is_string($rule['title'])) {
                    throw ApiException::validation("Rule #{$n} needs a string 'title'.");
                }
                $title  = $rule['title'];
                $detail = self::optionalString($rule, 'detail');
            } else {
                throw ApiException::validation("Rule #{$n} must be a string or an object with a 'title'.");
            }

            $title = self::cleanRuleText($title);
            if ($title === '') {
                throw ApiException::validation("Rule #{$n} has an empty title.");
            }
            if (mb_strlen($title) > self::RULE_TITLE_MAX) {
                throw ApiException::validation("Rule #{$n} title must be at most " . self::RULE_TITLE_MAX . ' characters.');
            }
            $detail = $detail === null ? null : self::cleanRuleText($detail);
            if ($detail === '') {
                $detail = null;
            }
            if ($detail !== null && mb_strlen($detail) > self::RULE_DETAIL_MAX) {
                throw ApiException::validation("Rule #{$n} detail must be at most " . self::RULE_DETAIL_MAX . ' characters.');
            }
            $out[] = ['title' => $title, 'detail' => $detail];
        }
        return $out;
    }

    /** Normalise newlines to spaces, strip control chars, collapse runs, trim. */
    private static function cleanRuleText(string $value): string
    {
        $value = preg_replace('/[\p{C}]+/u', ' ', $value) ?? $value;
        $value = trim((string)preg_replace('/\s+/', ' ', $value));
        return $value;
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
