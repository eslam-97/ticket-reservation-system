<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;

/**
 * `created` separates a fresh hold from the caller's own existing one, which
 * is the difference between 201 and 200: a client that retried after a lost
 * response gets its reservation back rather than a conflict.
 */
final class CreateReservationResult
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly bool $created,
    ) {}
}
