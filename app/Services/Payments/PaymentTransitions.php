<?php

namespace App\Services\Payments;

use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Payments\DTO\PaymentEvent;
use App\Payments\DTO\PaymentEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Applies one provider event to our books.
 *
 * Used by both the webhook and payments:reconcile, because two entry points
 * to the same outcome must not drift: same guards, same tests, one place to
 * change.
 *
 * MUST be called inside a transaction opened by the caller — the webhook
 * needs the webhook_events row and this work to commit or roll back together.
 * The locking, however, is done here, in the system-wide order: seats (by id)
 * -> reservation -> payment -> claims. Rows are located with plain reads
 * first, because starting from a payment would tempt this flow into locking a
 * payment before its reservation, which is exactly the cycle the order
 * exists to prevent.
 */
class PaymentTransitions
{
    public function apply(PaymentEvent $event, string $source, string $provider): void
    {
        // Providers send far more than we act on. Unknown events are
        // acknowledged and ignored: refusing them only makes the provider
        // retry something we will never do anything with.
        if ($event->type === PaymentEventType::Unknown) {
            Log::info('Ignoring a provider event we do not act on.', [
                'event_id' => $event->eventId,
                'source' => $source,
            ]);

            return;
        }

        $payment = $this->locate($event, $provider);

        if (! $payment instanceof Payment) {
            // An event for something we have no record of. Acknowledged, not
            // retried: a 500 here would make the provider redeliver forever.
            Log::warning('Orphan payment event: no attempt matches it.', [
                'event_id' => $event->eventId,
                'payment_id' => $event->paymentId,
                'provider_ref' => $event->providerRef,
                'provider' => $provider,
                'source' => $source,
            ]);

            return;
        }

        // The seat set is immutable after creation, so reading it unlocked is
        // safe, and we need it before we know which seats to lock.
        $seatIds = $payment->reservation->seatIds();

        [$reservation, $payment] = $this->lockInOrder($payment, $seatIds);

        Log::withContext([
            'reservation_id' => $reservation->getKey(),
            'payment_id' => $payment->getKey(),
            'event_id' => $event->eventId,
            ...($source === 'webhook' ? ['webhook_event_id' => $event->eventId] : []),
        ]);

        if ($payment->isTerminal()) {
            Log::info('Event ignored: the attempt is already terminal.', [
                'status' => $payment->status->value,
            ]);

            return;
        }

        // In the timeout case the user can pay before we ever stored the
        // provider's reference. Our own ULID travelled in the metadata, so
        // the attempt is found regardless; fill in the gap now.
        if ($payment->provider_ref === null && $event->providerRef !== null) {
            $payment->forceFill(['provider_ref' => $event->providerRef])->save();

            Log::info('Backfilled the provider reference from the event.');
        }

        match ($event->type) {
            PaymentEventType::Failed => $this->applyFailure($payment),
            PaymentEventType::Expired => $this->applyExpiry($payment),
            PaymentEventType::Succeeded => $this->applySuccess($event, $payment, $reservation, $seatIds),
            PaymentEventType::Unknown => null,
        };
    }

    /**
     * Resolve the attempt by our own identifier first.
     *
     * The payment ULID is written before the provider call, so it is always
     * present in the session metadata; the provider's reference may not have
     * reached us at all.
     */
    private function locate(PaymentEvent $event, string $provider): ?Payment
    {
        // The provider is part of an attempt's identity. A signed Stripe
        // event naming a `fake` attempt's id is not an event about that
        // attempt, however valid its signature — so every lookup is scoped to
        // the provider the event arrived from. Required rather than optional:
        // a caller able to skip the check is a caller able to defeat it.
        $scoped = fn (): Builder => Payment::query()->where('provider', $provider);

        if ($event->paymentId !== null) {
            $payment = $scoped()->whereKey($event->paymentId)->first();

            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        if ($event->providerRef !== null) {
            // provider_ref is only unique *within* a provider, so this lookup
            // in particular has no business crossing that boundary.
            return $scoped()->where('provider_ref', $event->providerRef)->first();
        }

        return null;
    }

    /**
     * @param  list<int>  $seatIds
     * @return array{0: Reservation, 1: Payment}
     */
    private function lockInOrder(Payment $payment, array $seatIds): array
    {
        // Seats first, ordered by id. They are never written in this design,
        // but a rule with an exception has to be re-proven on every change.
        if ($seatIds !== []) {
            Seat::query()->whereIn('id', $seatIds)->orderBy('id')->lockForUpdate()->get();
        }

        $reservation = Reservation::query()
            ->whereKey($payment->reservation_id)
            ->lockForUpdate()
            ->firstOrFail();

        $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        return [$reservation, $locked];
    }

    /**
     * A failed payment leaves the hold alone: the user can try again until it
     * expires, and expiry is what frees the seats.
     */
    private function applyFailure(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Initiated) {
            return;
        }

        $payment->transitionTo(PaymentStatus::Failed, [
            'failure_code' => 'payment_failed',
            'processed_at' => now(),
        ]);

        Log::info('Attempt failed; the reservation is untouched and still payable.');
    }

    private function applyExpiry(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Initiated) {
            return;
        }

        $payment->transitionTo(PaymentStatus::Expired, ['processed_at' => now()]);

        Log::info('Provider session expired; the reservation is untouched.');
    }

    /**
     * @param  list<int>  $seatIds
     */
    private function applySuccess(PaymentEvent $event, Payment $payment, Reservation $reservation, array $seatIds): void
    {
        // Money first: a payment that does not match the attempt is never
        // allowed to confirm anything, whatever the reservation says.
        if ($event->amount !== (int) $payment->amount || $event->currency !== $payment->currency) {
            $payment->transitionTo(PaymentStatus::Mismatched, [
                'raw_payload' => [...($payment->raw_payload ?? []), 'event' => $event->raw],
                'processed_at' => now(),
            ]);

            Log::error('Payment amount or currency did not match the attempt.', [
                'expected' => ['amount' => (int) $payment->amount, 'currency' => $payment->currency],
                'received' => ['amount' => $event->amount, 'currency' => $event->currency],
            ]);

            return;
        }

        if ($payment->status !== PaymentStatus::Initiated) {
            // Already succeeded: a duplicate delivery of an event we have
            // acted on. Nothing left to do.
            Log::info('Duplicate success for an attempt that already succeeded.');

            return;
        }

        $payment->transitionTo(PaymentStatus::Succeeded, [
            'processed_at' => now(),
            'raw_payload' => [...($payment->raw_payload ?? []), 'event' => $event->raw],
        ]);

        match ($reservation->effectiveStatus()) {
            // The ordinary path.
            ReservationStatus::Pending => $this->confirm($reservation),

            // Paid after the hold lapsed. Stripe sessions cannot expire in
            // under 30 minutes, so this is routine rather than exotic.
            ReservationStatus::Expired => $this->confirmLate($payment, $reservation, $seatIds),

            // The user walked away. Cancellation is final: a payment that
            // lands afterwards is refunded, never allowed to resurrect it.
            ReservationStatus::Cancelled => $this->refund($payment, 'reservation was cancelled'),

            // Two attempts paid for one reservation.
            ReservationStatus::Confirmed => $this->doublePayment($payment),
        };
    }

    private function confirm(Reservation $reservation): void
    {
        $reservation->transitionTo(ReservationStatus::Confirmed);

        Log::info('Reservation confirmed; its claims stay active.');
    }

    /**
     * The one named backward edge: expired -> confirmed.
     *
     * Refunding someone for a seat nobody else took is worse for them and for
     * the business — card fees do not come back — so a late payment completes
     * the hold when it still can. `starts_at` is deliberately not re-checked:
     * finishing an existing hold is not a new sale.
     *
     * @param  list<int>  $seatIds
     */
    private function confirmLate(Payment $payment, Reservation $reservation, array $seatIds): void
    {
        // The seats are locked, so these plain reads cannot go stale under us.
        //
        // Only a claim belonging to *another* reservation means the seat was
        // retaken. This reservation's own claims are not a conflict: if the
        // sweep has not run yet they are simply still sitting there, and
        // nobody else has taken anything.
        $takenByOthers = ReservationSeat::query()
            ->active()
            ->whereIn('seat_id', $seatIds)
            ->where('reservation_id', '!=', $reservation->getKey())
            ->pluck('seat_id')
            ->map(intval(...))
            ->all();

        if ($takenByOthers !== []) {
            Log::info('Late payment, but the seats were retaken.', ['taken_seat_ids' => $takenByOthers]);

            $this->refund($payment, 'seats were retaken after expiry');

            return;
        }

        // Only the seats this reservation has actually let go of need a new
        // claim. Re-inserting one it still holds would trip the
        // one-active-claim-per-seat index for no reason.
        $stillHeld = $reservation->claims()
            ->active()
            ->pluck('seat_id')
            ->map(intval(...))
            ->all();

        $needed = array_values(array_diff($seatIds, $stillHeld));

        if ($needed !== []) {
            // Fresh rows rather than un-releasing the old ones: the released
            // rows are history, and history is not rewritten.
            $now = now();

            $prices = $reservation->claims()
                ->whereIn('seat_id', $needed)
                ->pluck('unit_price', 'seat_id');

            ReservationSeat::query()->insert(
                collect($needed)->map(fn (int $seatId): array => [
                    'reservation_id' => $reservation->getKey(),
                    'seat_id' => $seatId,
                    // The original snapshot, not today's price.
                    'unit_price' => (int) ($prices[$seatId] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }

        $reservation->transitionTo(ReservationStatus::Confirmed);

        Log::info('Late payment confirmed: the seats were still free.', [
            'seat_ids' => $seatIds,
            'claims_reinstated' => $needed,
        ]);
    }

    private function refund(Payment $payment, string $why): void
    {
        $payment->transitionTo(PaymentStatus::RefundPending, [
            'next_retry_at' => now(),
            'attempts' => 0,
        ]);

        Log::info('Attempt queued for refund.', ['reason' => $why]);
    }

    private function doublePayment(Payment $payment): void
    {
        Log::error('Double payment: this reservation was already confirmed by another attempt.');

        $this->refund($payment, 'reservation was already confirmed');
    }
}
