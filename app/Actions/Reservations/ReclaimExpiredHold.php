<?php

namespace App\Actions\Reservations;

use App\Domain\Reservations\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Settle one reservation that the caller believes is past its deadline.
 *
 * PRECONDITION: the caller has already locked the seat rows involved, and is
 * inside a transaction. This routine locks only the reservation row and then
 * writes that reservation's claims, which keeps the system-wide lock order
 * seats -> reservations -> reservation_seats intact. Calling it without the
 * seat locks held would take a reservation lock before its seats and reopen
 * exactly the cycle that order exists to prevent.
 *
 * The re-read under the lock is the whole point of the routine. Between the
 * caller's unlocked classification and this lock, a late webhook may have
 * confirmed the very hold that was about to be reclaimed, or the sweep may
 * have expired it first. Whoever commits first wins; this reports what it
 * found rather than assuming.
 *
 * Nothing here decides what an outcome means: a confirmed hold is a conflict
 * to the reserve path and merely a no-op to the sweep, so the meaning belongs
 * to the caller.
 */
class ReclaimExpiredHold
{
    public function handle(string $reservationId): ReclaimOutcome
    {
        $now = now();

        $reservation = Reservation::query()->whereKey($reservationId)->lockForUpdate()->first();

        // Reservations are never deleted, so this is vanishing-rare; there is
        // nothing left to reclaim either way.
        if (! $reservation instanceof Reservation) {
            return ReclaimOutcome::AlreadyReleased;
        }

        Log::withContext([
            'reservation_id' => $reservation->getKey(),
            'event_id' => $reservation->event_id,
        ]);

        if ($reservation->status === ReservationStatus::Confirmed) {
            Log::info('Reclaim found the hold confirmed under lock; leaving it alone.');

            return ReclaimOutcome::Confirmed;
        }

        if ($reservation->status === ReservationStatus::Pending) {
            // expires_at never moves, because holds are never extended, so a
            // hold that looked lapsed a moment ago cannot have become live.
            // Report it rather than touching a hold somebody still owns.
            if ($reservation->expires_at > $now) {
                Log::info('Reclaim found the hold still live under lock; leaving it alone.');

                return ReclaimOutcome::StillPending;
            }

            $reservation->transitionTo(ReservationStatus::Expired);
            $this->releaseClaims($reservation, $now);

            return ReclaimOutcome::Expired;
        }

        // Already expired or cancelled. Releasing again is a no-op in the
        // normal case, and repairs the books when something settled a
        // reservation without letting go of its seats.
        $this->releaseClaims($reservation, $now);

        return ReclaimOutcome::AlreadyReleased;
    }

    /**
     * Released rows stay as history; only released_at IS NULL is a live claim.
     */
    private function releaseClaims(Reservation $reservation, CarbonInterface $now): void
    {
        $released = $reservation->claims()
            ->whereNull('released_at')
            ->update(['released_at' => $now, 'updated_at' => $now]);

        if ($released > 0) {
            Log::info('Released the claims of a settled hold.', [
                'claims_released' => $released,
                'stored_status' => $reservation->status->value,
            ]);
        }
    }
}
