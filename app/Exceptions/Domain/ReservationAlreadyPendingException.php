<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user already holds a different seat set for this event.
 *
 * Deliberately not a replace or a merge: silently releasing the old seats
 * could orphan an open checkout session, so the client is told which
 * reservation is in the way and continues or cancels it explicitly.
 */
class ReservationAlreadyPendingException extends DomainException
{
    public function __construct(string $reservationId)
    {
        parent::__construct(
            'reservation_already_pending',
            'You already hold a different set of seats for this event.',
            Response::HTTP_CONFLICT,
            ['reservation_id' => $reservationId],
        );
    }
}
