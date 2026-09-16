<?php

namespace App\Actions\Reservations;

use App\Actions\Payments\CloseOpenAttempt;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\Domain\ReservationNotPendingException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give the seats back.
 *
 * T1 cancels the reservation and releases its claims; the provider call to
 * kill any open hosted page happens after that transaction has committed, so
 * no lock is held across it.
 *
 * The attempt is deliberately *not* marked cancelled in T1. `cancelled` on a
 * payment means the provider has confirmed the session can no longer
 * complete, and until it says so the session might still be paid. Writing it
 * early would force a cancelled -> succeeded backward edge the moment that
 * happened; leaving it initiated lets a late success run
 * initiated -> succeeded -> refund_pending instead.
 *
 * Cancellation of the reservation, by contrast, is final. A payment that
 * lands afterwards is refunded and never resurrects the hold.
 */
class CancelReservation
{
    public function __construct(private readonly CloseOpenAttempt $closeAttempt) {}

    public function handle(User $user, Reservation $reservation): Reservation
    {
        Log::withContext([
            'reservation_id' => $reservation->getKey(),
            'event_id' => $reservation->event_id,
        ]);

        // Located unlocked: the seat set never changes after creation, and we
        // must know which seats to lock before locking anything.
        $seatIds = $reservation->seatIds();

        $open = DB::transaction(function () use ($reservation, $seatIds): ?Payment {
            if ($seatIds !== []) {
                Seat::query()->whereIn('id', $seatIds)->orderBy('id')->lockForUpdate()->get();
            }

            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            // Re-read under lock: a webhook may have confirmed this hold, or
            // the sweep expired it, since the request arrived.
            if (! $locked->isEffectivelyPending()) {
                throw ReservationNotPendingException::for($locked);
            }

            $open = Payment::query()
                ->where('reservation_id', $locked->getKey())
                ->where('status', PaymentStatus::Initiated)
                ->lockForUpdate()
                ->first();

            $locked->transitionTo(ReservationStatus::Cancelled);

            $released = $locked->claims()
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            Log::info('Reservation cancelled and its seats released.', [
                'claims_released' => $released,
                'open_attempt_id' => $open?->getKey(),
            ]);

            $reservation->setRawAttributes($locked->getAttributes(), sync: true);

            return $open;
        }, attempts: 3);

        // Close the hosted page the user walked away from. An attempt with no
        // provider_ref has nothing to close yet — checkout T2 or reconcile
        // settles that one.
        if ($open instanceof Payment && $open->provider_ref !== null) {
            $this->closeAttempt->handle($open);
        }

        return $reservation->refresh();
    }
}
