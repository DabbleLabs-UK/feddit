<?php
declare(strict_types=1);

/**
 * Avatar handling - the one genuinely hostile surface on the profile feature, so
 * it is treated as such. An uploaded avatar arrives as base64 through the
 * bot-authenticated API. We NEVER trust the filename or the declared content
 * type: an image is proven by decoding it. What we store is never what was sent
 * - we decode, centre-crop to a fixed square, resample to 128x128, drop every
 * scrap of metadata, and write a fresh PNG in one known format. That single
 * re-encode kills polyglot files, embedded payloads and EXIF leakage at once.
 *
 * Files live OUTSIDE the web root (storage/avatars/) and are only ever emitted
 * by a handler that hard-codes an image content-type, so an upload can never be
 * served as HTML or executed. Filenames are deterministic ({bot_id}.png) so a
 * re-upload replaces rather than accumulates.
 */
final class AvatarService
{
    /** The re-encoded avatar is always this many pixels square, PNG. */
    public const SIZE = 128;

    /** Default hard cap on the decoded upload, overridable from config. */
    private const DEFAULT_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB

    /** Default minimum seconds between avatar uploads for one bot (rate limit). */
    private const DEFAULT_MIN_SECONDS = 30;

    /** Image types we are willing to decode. Everything else is rejected. */
    private const ALLOWED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** Directory the avatars live in - deliberately outside public/. */
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/avatars';
    }

    /** Absolute path of a bot's avatar file (may not exist). */
    public static function path(int $botId): string
    {
        return self::dir() . '/' . $botId . '.png';
    }

    public static function exists(int $botId): bool
    {
        return is_file(self::path($botId));
    }

    private static function maxBytes(array $config): int
    {
        return (int)($config['avatar']['max_bytes'] ?? self::DEFAULT_MAX_BYTES);
    }

    private static function minSeconds(array $config): int
    {
        return (int)($config['avatar']['min_seconds'] ?? self::DEFAULT_MIN_SECONDS);
    }

    /**
     * Per-bot upload throttle: reject if this bot set an avatar within the
     * configured minimum interval. Counted off avatar_updated_at, so a churn of
     * re-uploads cannot slip past it. A limit of 0 disables the throttle.
     */
    public static function checkRate(array $config, ?string $lastUpdatedAt): void
    {
        $min = self::minSeconds($config);
        if ($min <= 0 || $lastUpdatedAt === null || $lastUpdatedAt === '') {
            return;
        }
        $lastTs = strtotime($lastUpdatedAt);
        if ($lastTs === false) {
            return;
        }
        $elapsed = time() - $lastTs;
        if ($elapsed < $min) {
            throw ApiException::rateLimited(sprintf(
                'Avatar uploads are limited to one per %d seconds. Try again in %d second(s).',
                $min,
                $min - $elapsed
            ));
        }
    }

    /**
     * Decode, validate and re-encode a base64 avatar, writing {bot_id}.png into
     * the storage dir. Returns nothing; throws ApiException(400) on anything that
     * is not a real, in-limits image. GD is required (php-gd on the server).
     *
     * @param string $base64 raw base64, or a data URI (data:image/png;base64,...)
     */
    public static function store(array $config, int $botId, string $base64): void
    {
        if (!function_exists('imagecreatefromstring')) {
            // Misconfiguration, not bad input - surface as a server error.
            throw new ApiException('unavailable', 'Image processing is not available on the server.', 503);
        }

        $maxBytes = self::maxBytes($config);

        // Strip an optional data-URI prefix, then the whitespace curl/JSON may add.
        $payload = preg_replace('#^data:[^,]*,#', '', trim($base64));
        $payload = preg_replace('/\s+/', '', (string)$payload);
        if ($payload === '' || $payload === null) {
            throw ApiException::validation('Avatar payload is empty.');
        }
        // Cheap upper bound before we allocate the decoded buffer: base64 inflates
        // bytes by ~4/3, so cap the encoded length to keep a huge string from ever
        // being decoded in the first place.
        if (strlen($payload) > (int)ceil($maxBytes * 4 / 3) + 1024) {
            throw self::tooBig($maxBytes);
        }
        $bytes = base64_decode($payload, true);
        if ($bytes === false) {
            throw ApiException::validation('Avatar must be valid base64.');
        }
        if (strlen($bytes) === 0) {
            throw ApiException::validation('Avatar payload is empty.');
        }
        if (strlen($bytes) > $maxBytes) {
            throw self::tooBig($maxBytes);
        }

        // Prove it is a real image by INSPECTING the bytes, not any declared type.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($info[2]) || !in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw ApiException::validation('That file is not a supported image (PNG, JPEG, GIF or WebP).');
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw ApiException::validation('That image could not be decoded.');
        }

        try {
            $square = self::squareResample($src);
        } finally {
            imagedestroy($src);
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException('unavailable', 'Could not store the avatar.', 503);
        }

        // Write to a temp file then rename, so a concurrent read never sees a
        // half-written PNG and the deterministic name flips atomically.
        $target = self::path($botId);
        $tmp    = $target . '.tmp';
        $ok = imagepng($square, $tmp, 6);
        imagedestroy($square);
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new ApiException('unavailable', 'Could not store the avatar.', 503);
        }
    }

    /** Delete a bot's avatar file if present. Best-effort; never throws. */
    public static function remove(int $botId): void
    {
        $path = self::path($botId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Centre-crop the source to a square and resample it to SIZE x SIZE on a
     * transparent canvas (so a PNG with alpha keeps it). The output GD image is
     * a fresh truecolor buffer carrying no metadata from the original.
     */
    private static function squareResample(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $sx = (int)floor(($w - $side) / 2);
        $sy = (int)floor(($h - $side) / 2);

        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, self::SIZE, self::SIZE, $transparent);
        imagealphablending($dst, true);

        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);
        imagesavealpha($dst, true);
        return $dst;
    }

    private static function tooBig(int $maxBytes): ApiException
    {
        $kb = (int)round($maxBytes / 1024);
        return ApiException::validation("Avatar is too large. The limit is {$kb} KB.");
    }
}
