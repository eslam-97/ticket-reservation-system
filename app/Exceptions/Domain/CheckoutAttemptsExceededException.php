<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Too many payment attempts have been made on this reservation.
 *
 * The cap exists so a hold cannot be used to hammer the provider. Expiry is
 * what eventually frees the seats; this only stops the calls.
 */
class CheckoutAttemptsExceededException extends DomainException
{
    public function __construct(int $attempts, int $max)
    {
        parent::__construct(
            'checkout_attempts_exceeded',
            'This reservation has reached its limit of payment attempts.',
            Response::HTTP_CONFLICT,
            ['attempts' => $attempts, 'max_attempts' => $max],
        );
    }
}
