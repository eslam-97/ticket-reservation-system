<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The provider definitively refused the request.
 *
 * Deliberately not a 503 and deliberately without a Retry-After: retrying
 * will never help. This is the operator's problem — a misconfiguration, an
 * unsupported currency, an amount below the provider's minimum — and telling
 * clients to keep trying would only hide it.
 */
class PaymentProviderRejectedException extends DomainException
{
    public function __construct(string $failureCode)
    {
        parent::__construct(
            'payment_provider_rejected',
            'The payment provider rejected this payment.',
            Response::HTTP_BAD_GATEWAY,
            ['failure_code' => $failureCode],
        );
    }
}
