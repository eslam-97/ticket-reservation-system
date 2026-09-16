<?php

namespace App\Actions\Payments;

use App\Domain\Payments\PaymentStatus;
use App\Exceptions\Domain\CheckoutAttemptsExceededException;
use App\Exceptions\Domain\PaymentProviderRejectedException;
use App\Exceptions\Domain\PaymentProviderUnavailableException;
use App\Exceptions\Domain\ReservationNotPendingException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Payments\DTO\CheckoutSession;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderTransientException;
use App\Payments\PaymentManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Open, or resume, the hosted checkout session for a reservation.
 *
 * Shaped T1 (commit) -> provider call -> T2 (commit). No lock outlives a
 * millisecond-scale transaction, so a ten-second provider timeout never
 * blocks the webhook or the sweep for this reservation.
 *
 * The provider is chosen once, when the attempt is created, and recorded on
 * the row. Every call about that attempt afterwards resolves its driver from
 * `payments.provider` — config decides who takes a *new* payment, never who
 * owns an existing one.
 *
 * The payments row is written in T1, *before* the provider is called, and its
 * ULID is both the idempotency key and the session metadata. That is what
 * makes every failure recoverable: a lost response replays the same key and
 * gets the original session back rather than minting a second one, and a
 * webhook that arrives before provider_ref was ever stored can still find its
 * attempt.
 */
class StartCheckout
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly CloseOpenAttempt $closeAttempt,
    ) {}

    public function handle(User $user, Reservation $reservation): CheckoutResult
    {
        Log::withContext([
            'reservation_id' => $reservation->getKey(),
            'event_id' => $reservation->event_id,
        ]);

        [$resumable, $attempt] = $this->openAttempt($reservation);

        // A live session already exists: hand back its URL without troubling
        // the provider at all.
        if ($resumable instanceof CheckoutResult) {
            Log::info('Resumed a live checkout session.', ['payment_id' => $resumable->paymentId]);

            return $resumable;
        }

        Log::withContext(['payment_id' => $attempt->getKey()]);

        $session = $this->createSession($attempt, $reservation);

        return $this->storeSession($attempt, $reservation, $session);
    }

    /**
     * T1: under the reservation lock, decide which attempt this checkout is.
     *
     * @return array{0: ?CheckoutResult, 1: Payment}
     */
    private function openAttempt(Reservation $reservation): array
    {
        return DB::transaction(function () use ($reservation): array {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isEffectivelyPending()) {
                throw ReservationNotPendingException::for($locked);
            }

            $open = Payment::query()
                ->where('reservation_id', $locked->getKey())
                ->where('status', PaymentStatus::Initiated)
                ->lockForUpdate()
                ->first();

            if ($open instanceof Payment && $open->provider_ref !== null) {
                $expiresAt = $this->sessionExpiry($open);

                // Case 1: the session is still live. Reuse it as it stands.
                if ($expiresAt !== null && $expiresAt->isFuture()) {
                    return [
                        new CheckoutResult(
                            paymentId: (string) $open->getKey(),
                            checkoutUrl: (string) ($open->raw_payload['checkout_url'] ?? ''),
                            providerRef: (string) $open->provider_ref,
                            sessionExpiresAt: $expiresAt,
                        ),
                        $open,
                    ];
                }

                // Case 2: the session has lapsed at the provider. Settle the
                // attempt and start a new one below.
                $open->transitionTo(PaymentStatus::Expired);
                $open = null;
            }

            // Case 3: an attempt with no provider_ref is the timeout case. Do
            // not mint a new one — reusing it is what replays the same
            // idempotency key and recovers the provider's original session.
            if ($open instanceof Payment) {
                Log::info('Reusing an attempt that never recorded a provider reference.');

                return [null, $open];
            }

            return [null, $this->newAttempt($locked)];
        }, attempts: 3);
    }

    private function newAttempt(Reservation $reservation): Payment
    {
        $max = (int) config('payments.checkout_max_attempts');

        // Every attempt that has already run its course. The cap limits
        // provider calls; expiry is what eventually frees the seats.
        $spent = Payment::query()
            ->where('reservation_id', $reservation->getKey())
            ->where('status', '!=', PaymentStatus::Initiated)
            ->count();

        if ($spent >= $max) {
            Log::info('Refused a checkout past the attempt cap.', ['attempts' => $spent]);

            throw new CheckoutAttemptsExceededException($spent, $max);
        }

        return Payment::query()->create([
            'reservation_id' => $reservation->getKey(),
            'provider' => $this->payments->getDefaultDriver(),
            // Server-side from the frozen snapshot. The client never sends an
            // amount, and the webhook checks this back against the provider.
            'amount' => (int) $reservation->total_amount,
            'currency' => $reservation->currency,
            'status' => PaymentStatus::Initiated,
            'attempts' => 0,
        ]);
    }

    /**
     * The provider call, outside every transaction.
     */
    private function createSession(Payment $attempt, Reservation $reservation): CheckoutSession
    {
        // Built from config plus the reservation id: the client never
        // supplies a URL, so there is no open redirect to exploit.
        $base = rtrim((string) config('payments.return_base_url'), '/')."/reservations/{$reservation->getKey()}";

        try {
            // The attempt's own provider, never the configured default. They
            // are identical for an attempt created moments ago — newAttempt()
            // stamps it from getDefaultDriver() — but case 3 reuses an
            // attempt that may predate a change to PAYMENTS_DRIVER, and
            // replaying its idempotency key at a different provider would
            // open a second session rather than recover the first.
            return $this->payments->driverFor($attempt->provider)->createCheckout(
                $attempt,
                $reservation,
                $base.'?checkout=success',
                $base.'?checkout=cancel',
            );
        } catch (ProviderTransientException $e) {
            // The outcome is unknown, so the attempt stays initiated and the
            // next call replays the same key.
            $this->countAttempt($attempt);

            Log::warning('Provider was unavailable during checkout.', ['reason' => $e->getMessage()]);

            throw new PaymentProviderUnavailableException;
        } catch (ProviderRejectedException $e) {
            $this->failAttempt($attempt, $e->failureCode());

            Log::error('Provider definitively rejected the checkout.', [
                'failure_code' => $e->failureCode(),
                'reason' => $e->getMessage(),
            ]);

            throw new PaymentProviderRejectedException($e->failureCode());
        }
    }

    /**
     * T2: store what the provider gave us, then check we still want it.
     */
    private function storeSession(Payment $attempt, Reservation $reservation, CheckoutSession $session): CheckoutResult
    {
        $stillPending = DB::transaction(function () use ($attempt, $reservation, $session): bool {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            $payment = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

            // Stored either way. Even when this reservation is no longer ours
            // to pay for, the reference is how the session gets closed.
            $payment->forceFill([
                'provider_ref' => $session->providerRef,
                'raw_payload' => [
                    ...($payment->raw_payload ?? []),
                    'checkout_url' => $session->url,
                    'session_expires_at' => $session->expiresAt->toIso8601String(),
                ],
            ])->save();

            $attempt->setRawAttributes($payment->getAttributes(), sync: true);

            return $locked->isEffectivelyPending();
        }, attempts: 3);

        if ($stillPending) {
            Log::info('Checkout session opened.', [
                'provider_ref' => $session->providerRef,
                'session_expires_at' => $session->expiresAt->toIso8601String(),
            ]);

            return new CheckoutResult(
                paymentId: (string) $attempt->getKey(),
                checkoutUrl: $session->url,
                providerRef: $session->providerRef,
                sessionExpiresAt: CarbonImmutable::parse($session->expiresAt),
            );
        }

        // The second finisher cleans up. While we were at the provider the
        // reservation was cancelled, expired or confirmed, so a live hosted
        // page now exists for something nobody can pay for. Close it here
        // rather than leaving the user staring at it; reconcile is the
        // backstop if this call fails.
        $reservation->refresh();

        Log::info('Reservation stopped being pending during checkout; closing the session.', [
            'stored_status' => $reservation->status->value,
        ]);

        $this->closeAttempt->handle($attempt);

        throw ReservationNotPendingException::for($reservation);
    }

    /**
     * Record that a provider call was spent, without changing the status.
     */
    private function countAttempt(Payment $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            Reservation::query()->whereKey($attempt->reservation_id)->lockForUpdate()->first();

            $payment = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if ($payment instanceof Payment && $payment->status === PaymentStatus::Initiated) {
                $payment->forceFill(['attempts' => (int) $payment->attempts + 1])->save();
                $attempt->setRawAttributes($payment->getAttributes(), sync: true);
            }
        }, attempts: 3);
    }

    private function failAttempt(Payment $attempt, string $failureCode): void
    {
        DB::transaction(function () use ($attempt, $failureCode): void {
            Reservation::query()->whereKey($attempt->reservation_id)->lockForUpdate()->first();

            $payment = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if ($payment instanceof Payment && $payment->status === PaymentStatus::Initiated) {
                $payment->transitionTo(PaymentStatus::Failed, [
                    'failure_code' => $failureCode,
                    'attempts' => (int) $payment->attempts + 1,
                ]);

                $attempt->setRawAttributes($payment->getAttributes(), sync: true);
            }
        }, attempts: 3);
    }

    private function sessionExpiry(Payment $attempt): ?CarbonImmutable
    {
        $raw = $attempt->raw_payload['session_expires_at'] ?? null;

        return is_string($raw) ? CarbonImmutable::parse($raw) : null;
    }
}
