<?php
declare(strict_types=1);

require_once __DIR__ . '/AvatarService.php';

/**
 * The locally-cached link-preview thumbnail store. It is the exact same shape as
 * the avatar store on purpose (files OUTSIDE the web root, a deterministic
 * {post_id}.png name, served only by a handler that hard-codes an image
 * content-type + nosniff), and it re-encodes through the SAME hardened image
 * pipeline as avatars (AvatarService::reencodeSquarePng) rather than being a
 * second pipeline.
 *
 * We CACHE, never hotlink: fetching the publisher's og:image once server-side,
 * re-encoding it to a small square PNG and serving our own copy means a visitor's
 * IP never reaches the publisher and a rotated remote URL never breaks the image.
 * The re-encode also proves the bytes are genuinely an image and strips all
 * metadata - a remote image is as untrusted as an uploaded one.
 */
final class ThumbnailService
{
    /** old.reddit's thumbnail is a 70px square; match it so rows look native. */
    public const SIZE = 70;

    /** Directory the cached thumbnails live in - deliberately outside public/. */
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/thumbs';
    }

    /** Absolute path of a post's cached thumbnail (may not exist). */
    public static function path(int $postId): string
    {
        return self::dir() . '/' . $postId . '.png';
    }

    public static function exists(int $postId): bool
    {
        return is_file(self::path($postId));
    }

    /** The public served path for a post's thumbnail (handled by /thumb/{id}.png). */
    public static function publicUrl(int $postId): string
    {
        return '/thumb/' . $postId . '.png';
    }

    /**
     * Re-encode already-fetched image bytes to a SIZE x SIZE PNG and store it as
     * {post_id}.png. Returns the served public path on success, or null if the
     * bytes are not a decodable supported image (the caller records og_status
     * accordingly). Writes atomically (temp file + rename).
     */
    public static function store(int $postId, string $bytes): ?string
    {
        $png = AvatarService::reencodeSquarePng($bytes, self::SIZE);
        if ($png === null) {
            return null;   // not a real/supported image
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $target = self::path($postId);
        $tmp    = $target . '.tmp';
        if (@file_put_contents($tmp, $png) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            return null;
        }
        return self::publicUrl($postId);
    }

    /** Delete a post's cached thumbnail if present. Best-effort; never throws. */
    public static function remove(int $postId): void
    {
        $path = self::path($postId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
