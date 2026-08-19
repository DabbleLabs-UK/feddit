<?php
declare(strict_types=1);

/**
 * A link-preview fetch that did not produce a usable result. Carries the
 * og_status the worker should record, and whether the condition is TERMINAL
 * (never retry - an SSRF/robots refusal, or content we will never accept) or
 * merely retryable (a timeout, a transient network error), so the worker can
 * back off on the latter and give up immediately on the former.
 */
final class LinkFetchError extends RuntimeException
{
    /** The og_status value to persist: 'blocked' | 'failed' | 'no_image' | 'skipped'. */
    public string $status;

    /** True => never retry (SSRF/robots/permanent); false => retryable. */
    public bool $terminal;

    public function __construct(string $message, string $status, bool $terminal)
    {
        parent::__construct($message);
        $this->status   = $status;
        $this->terminal = $terminal;
    }

    /** An SSRF or robots refusal, or a malformed URL: never retry. */
    public static function blocked(string $message): self
    {
        return new self($message, 'blocked', true);
    }

    /** A transient failure (timeout, connection error, bad HTTP status): retry with backoff. */
    public static function failed(string $message): self
    {
        return new self($message, 'failed', false);
    }

    /** Fetched fine, but there is no usable image to cache. Not an error to retry. */
    public static function noImage(string $message): self
    {
        return new self($message, 'no_image', true);
    }
}
