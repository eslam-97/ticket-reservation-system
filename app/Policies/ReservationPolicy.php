<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

/**
 * Ownership, and only ownership.
 *
 * Whether a reservation can still be paid for or cancelled is a question
 * about its effective status, not about who is asking, so that guard lives in
 * the actions where it can throw the right domain exception. Here the answer
 * is always the same: user A never touches user B's reservation.
 */
class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $this->owns($user, $reservation);
    }

    public function checkout(User $user, Reservation $reservation): bool
    {
        return $this->owns($user, $reservation);
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $this->owns($user, $reservation);
    }

    private function owns(User $user, Reservation $reservation): bool
    {
        return (int) $user->getKey() === (int) $reservation->user_id;
    }
}
