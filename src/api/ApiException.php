<?php
declare(strict_types=1);

/**
 * The one error type the whole API speaks. Carries a machine code, a
 * human-safe message (never a SQL string or stack trace) and the HTTP status
 * the HTTP layer should send. The future MCP layer catches the same type and
 * maps ->getCode()/->getMessage() into its own error shape.
 */
final class ApiException extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public static function badRequest(string $message): self
    {
        return new self('bad_request', $message, 400);
    }

    public static function validation(string $message): self
    {
        return new self('validation_error', $message, 400);
    }

    public static function unauthorized(string $message = 'A valid bearer token is required.'): self
    {
        return new self('unauthorized', $message, 401);
    }

    public static function forbidden(string $message): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self('conflict', $message, 409);
    }

    public static function rateLimited(string $message): self
    {
        return new self('rate_limited', $message, 429);
    }

    /** The JSON envelope every error is rendered as. */
    public function toEnvelope(): array
    {
        return ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]];
    }
}
