<?php

namespace App\Actions\Payments;

use App\Domain\Payments\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ask the provider to kill an open hosted session, and record the answer.
 *
 * Shared by cancel, checkout T2 and reconcile, so the three cannot drift: one
 * set of guards, one place to change.
 *
 * The provider call happens outside any transaction. That is not an
 * optimisation — holding the reservation lock across a ten-second HTTP
 * timeout would block the webhook and the sweep for that reservation.
 *
 * `cancelled` is written only when the provider has confirmed the session can
 * no longer complete. Anything else leaves the attempt `initiated` for the
 * webhook or reconcile to settle, which is what keeps the payment machine
 * free of backward edges: if the session completes anyway, the attempt moves
 * initiated -> succeeded -> refund_pending rather than needing a cancelled
 * it would have to undo.
 *
 * The gateway is resolved from the *attempt's* provider, never from the
 * configured default. An attempt opened against Stripe must be closed at
 * Stripe even if PAYMENTS_DRIVER has since been flipped to fake — otherwise
 * a driver that has never heard of the session would report it closed, and
 * `cancelled` is terminal, so a real payment landing afterwards would be
 * ignored: money taken, no seat, no refund.
 */
class CloseOpenAttempt
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function handle(Payment $attempt): void
    {
        Log::withContext([
            'payment_id' => $attempt->getKey(),
            'reservation_id' => $attempt->reservation_id,
        ]);

        if ($attempt->status !== PaymentStatus::Initiated) {
            return;
        }

        // Nothing was ever created at the provider, so there is nothing to
        // close. Reconcile expires this attempt once it is older than any
        // session could be.
        if ($attempt->provider_ref === null) {
            Log::info('Attempt has no provider reference; leaving it for reconcile.');

            return;
        }

        $result = $this->payments->driverFor($attempt->provider)->cancelCheckout($attempt);

        DB::transaction(function () use ($attempt, $result): void {
            // Lock order: the reservation is the mutex for everything under
            // it, so it comes first even though only the payment is written.
            Reservation::query()->whereKey($attempt->reservation_id)->lockForUpdate()->first();

            $locked = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            // Re-read under lock: a webhook may have settled this attempt
            // while we were talking to the provider.
            if (! $locked instanceof Payment || $locked->status !== PaymentStatus::Initiated) {
                Log::info('Attempt was already settled while the session was being closed.', [
                    'status' => $locked?->status->value,
                ]);

                return;
            }

            if (! $result->confirmed) {
                // The session may still complete. Leaving it initiated is the
                // correct outcome, not a failure.
                Log::info('Provider would not confirm the session is closed; leaving the attempt open.', [
                    'reason' => $result->reason,
                ]);

                return;
            }

            $locked->transitionTo(PaymentStatus::Cancelled, [
                'raw_payload' => [...($locked->raw_payload ?? []), 'cancel' => $result->reason],
            ]);

            Log::info('Attempt cancelled after the provider confirmed the session is closed.', [
                'reason' => $result->reason,
            ]);

            $attempt->setRawAttributes($locked->getAttributes(), sync: true);
        }, attempts: 3);
    }
}
