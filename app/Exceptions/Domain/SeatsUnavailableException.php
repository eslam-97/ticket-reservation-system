<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Some of the requested seats cannot be held.
 *
 * Two different causes share this code because the client does the same thing
 * about both — pick other seats, or retry — but the message distinguishes
 * them. `nowait` means another request holds the seat row right now and lost
 * no time waiting to find out (MySQL 3572); the plain case means a live hold
 * or a confirmed sale already owns the seat.
 */
class SeatsUnavailableException extends DomainException
{
    /**
     * @param  array<int, int|string>  $seatIds
     */
    public function __construct(array $seatIds, bool $nowait = false)
    {
        parent::__construct(
            'seats_unavailable',
            $nowait
                ? 'Someone else is reserving one of those seats right now. Please try again.'
                : 'Some of those seats are no longer available.',
            Response::HTTP_CONFLICT,
            ['seat_ids' => array_values(array_unique(array_map(intval(...), $seatIds)))],
        );
    }
}
