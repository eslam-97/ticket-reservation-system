<?php

namespace App\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider definitively refused the request: a 4xx such as an invalid
 * request, an amount below the provider's minimum, an unsupported currency,
 * or a restricted account.
 *
 * Retrying will never help, so this is the operator's problem, not the
 * client's. The attempt is marked `failed` with the code below.
 */
class ProviderRejectedException extends RuntimeException
{
    public function __construct(
        private readonly string $failureCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : "The payment provider rejected the request [{$failureCode}].", 0, $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
