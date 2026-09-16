<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The provider could not be reached, or the outcome is unknown.
 *
 * The client's problem, and worth retrying: the attempt stays `initiated` and
 * the next call replays the same idempotency key, so a session the provider
 * may already have created is recovered rather than duplicated.
 */
class PaymentProviderUnavailableException extends DomainException
{
    private const RETRY_AFTER_SECONDS = 5;

    public function __construct()
    {
        parent::__construct(
            'payment_provider_unavailable',
            'The payment provider is temporarily unavailable. Please try again.',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['Retry-After' => (string) self::RETRY_AFTER_SECONDS];
    }
}
