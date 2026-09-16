<?php

namespace App\Domain\Reservations;

/**
 * The reservation state machine.
 *
 * Forward-only with exactly one named backward edge: expired → confirmed,
 * taken only by confirmLate. Expiry is system-caused and may be undone by a
 * late payment; cancellation is user intent and is final, so a stale payment
 * can never resurrect a reservation the user explicitly abandoned.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => in_array($to, [self::Confirmed, self::Expired, self::Cancelled], true),
            self::Expired => $to === self::Confirmed,
            self::Confirmed, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed, self::Cancelled => true,
            self::Pending, self::Expired => false,
        };
    }
}
