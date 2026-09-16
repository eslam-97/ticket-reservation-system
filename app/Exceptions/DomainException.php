<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for every business-rule failure.
 *
 * Each subclass carries the three things the client contract needs: a stable
 * error code, the HTTP status it maps to, and structured details. The
 * exception handler renders them as { "error": { code, message, details } },
 * so no controller ever builds an error body by hand.
 */
abstract class DomainException extends RuntimeException
{
    public function __construct(
        protected readonly string $errorCode,
        string $message,
        protected readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        protected readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Response headers this failure needs, such as a Retry-After telling the
     * client when it is worth coming back.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [];
    }
}
