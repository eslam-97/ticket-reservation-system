<?php

namespace App\Console\Commands;

use App\Actions\Payments\CloseOpenAttempt;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\DTO\PaymentEventType;
use App\Payments\Exceptions\ProviderTransientException;
use App\Payments\PaymentManager;
use App\Services\Payments\PaymentTransitions;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Self-healing for everything the happy path can drop.
 *
 * Four independent passes, each idempotent and each safe to run again a
 * minute later. A provider that is unreachable for one row never stops the
 * rest: transient failures are caught per row, because the whole point of
 * this command is to make progress on what it can.
 *
 * It shares PaymentTransitions with the webhook, so an attempt settled here
 * goes through exactly the guards a webhook would have applied. Two routes to
 * the same outcome must not drift.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Settle payment attempts the webhook never resolved, and retry refunds';

    /** Longest a refund is ever made to wait between attempts. */
    private const MAX_BACKOFF_MINUTES = 60;

    public function __construct(
        private readonly PaymentManager $payments,
        private readonly PaymentTransitions $transitions,
        private readonly CloseOpenAttempt $closeAttempt,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('pass A (poll stale attempts): '.$this->pollStaleAttempts());
        $this->line('pass B (expire ref-less attempts): '.$this->expireRefLessAttempts());
        $this->line('pass C (close orphaned sessions): '.$this->closeOrphanedSessions());
        $this->line('pass D (retry refunds): '.$this->retryRefunds());

        return self::SUCCESS;
    }

    /**
     * Pass A: the webhook never arrived, so ask the provider instead.
     */
    private function pollStaleAttempts(): string
    {
        $cutoff = now()->subMinutes((int) config('payments.reconcile_initiated_after_minutes'));

        $attempts = Payment::query()
            ->where('status', PaymentStatus::Initiated)
            ->whereNotNull('provider_ref')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        $settled = 0;
        $open = 0;
        $unreachable = 0;

        foreach ($attempts as $attempt) {
            try {
                $event = $this->payments->driverFor($attempt->provider)->fetchStatus($attempt);
            } catch (ProviderTransientException $e) {
                // This row only. The next run tries again.
                $unreachable++;

                Log::warning('Reconcile could not reach the provider.', [
                    'payment_id' => $attempt->getKey(),
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            if ($event->type === PaymentEventType::Unknown) {
                // Still open at the provider: nothing has happened yet.
                $open++;

                continue;
            }

            DB::transaction(fn () => $this->transitions->apply($event, 'reconcile', $attempt->provider), attempts: 3);

            $settled++;
        }

        return "settled {$settled}, still open {$open}, unreachable {$unreachable}";
    }

    /**
     * Pass B: an attempt that never recorded a provider reference.
     *
     * The checkout call timed out and no session id ever reached us. Once the
     * attempt is older than any session could possibly live, there is nothing
     * left that could pay it, so it is safe to settle.
     */
    private function expireRefLessAttempts(): string
    {
        $cutoff = now()->subMinutes((int) config('payments.max_session_lifetime_minutes'));

        $attempts = Payment::query()
            ->where('status', PaymentStatus::Initiated)
            ->whereNull('provider_ref')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        $expired = 0;

        foreach ($attempts as $attempt) {
            DB::transaction(function () use ($attempt, &$expired): void {
                Reservation::query()->whereKey($attempt->reservation_id)->lockForUpdate()->first();

                $locked = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

                if (! $locked instanceof Payment || $locked->status !== PaymentStatus::Initiated) {
                    return;
                }

                $locked->transitionTo(PaymentStatus::Expired, ['processed_at' => now()]);

                Log::info('Reconcile expired an attempt that never got a provider reference.', [
                    'payment_id' => $locked->getKey(),
                ]);

                $expired++;
            }, attempts: 3);
        }

        return "expired {$expired}";
    }

    /**
     * Pass C: the backstop for the checkout-versus-cancel race.
     *
     * An open attempt whose reservation is no longer payable means a live
     * hosted page for something nobody can buy. Normally cancel or checkout
     * T2 closes it; this catches the times neither did.
     */
    private function closeOrphanedSessions(): string
    {
        $now = now();

        $attempts = Payment::query()
            ->where('status', PaymentStatus::Initiated)
            ->whereHas('reservation', fn (Builder $query) => $this->notEffectivelyPending($query, $now))
            ->orderBy('id')
            ->get();

        $closed = 0;
        $unreachable = 0;

        foreach ($attempts as $attempt) {
            try {
                $this->closeAttempt->handle($attempt);
                $closed++;
            } catch (Throwable $e) {
                $unreachable++;

                Log::warning('Reconcile could not close an orphaned session.', [
                    'payment_id' => $attempt->getKey(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return "closed {$closed}, unreachable {$unreachable}";
    }

    /**
     * Pass D: refunds, with exponential backoff.
     *
     * The attempt counter is incremented *before* the provider call, so a
     * call that never returns still costs an attempt and still gets a later
     * next_retry_at. Otherwise a refund that times out every time would be
     * retried in a tight loop forever.
     */
    private function retryRefunds(): string
    {
        $max = (int) config('payments.refund_max_attempts');

        $attempts = Payment::query()
            ->where('status', PaymentStatus::RefundPending)
            ->where('next_retry_at', '<=', now())
            ->where('attempts', '<', $max)
            ->orderBy('id')
            ->get();

        $refunded = 0;
        $retrying = 0;
        $failed = 0;

        foreach ($attempts as $attempt) {
            $spent = $this->countRefundAttempt($attempt);

            $result = $this->payments->driverFor($attempt->provider)->refund($attempt);

            DB::transaction(function () use ($attempt, $result, &$refunded, &$retrying, &$failed): void {
                Reservation::query()->whereKey($attempt->reservation_id)->lockForUpdate()->first();

                $locked = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

                if (! $locked instanceof Payment || $locked->status !== PaymentStatus::RefundPending) {
                    return;
                }

                if ($result->succeeded) {
                    $locked->transitionTo(PaymentStatus::Refunded, [
                        'processed_at' => now(),
                        'raw_payload' => [
                            ...($locked->raw_payload ?? []),
                            'refund_ref' => $result->providerRefundRef,
                        ],
                    ]);

                    Log::info('Refund completed.', ['payment_id' => $locked->getKey()]);

                    $refunded++;

                    return;
                }

                if ($result->transient) {
                    // Left refund_pending with a later next_retry_at.
                    $retrying++;

                    return;
                }

                $failed++;

                Log::error('Refund was definitively refused by the provider.', [
                    'payment_id' => $locked->getKey(),
                    'attempts' => $locked->attempts,
                ]);
            }, attempts: 3);

            if (! $result->succeeded && $spent >= $max) {
                // Nothing automatic will pick this up again: the query above
                // filters it out from here on.
                Log::error('Refund requires manual intervention.', [
                    'payment_id' => $attempt->getKey(),
                    'reservation_id' => $attempt->reservation_id,
                    'attempts' => $spent,
                ]);
            }
        }

        return "refunded {$refunded}, retrying {$retrying}, failed {$failed}";
    }

    /**
     * Spend one refund attempt and push the next one further out.
     *
     * @return int the attempt number just spent
     */
    private function countRefundAttempt(Payment $attempt): int
    {
        $spent = 0;

        DB::transaction(function () use ($attempt, &$spent): void {
            Reservation::query()->whereKey($attempt->reservation_id)->lockForUpdate()->first();

            $locked = Payment::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

            $spent = (int) $locked->attempts + 1;

            $locked->forceFill([
                'attempts' => $spent,
                'next_retry_at' => now()->addMinutes(min(2 ** $spent, self::MAX_BACKOFF_MINUTES)),
            ])->save();

            $attempt->setRawAttributes($locked->getAttributes(), sync: true);
        }, attempts: 3);

        return $spent;
    }

    /**
     * The effective-status rule, expressed in SQL, with `now` bound from PHP.
     *
     * @param  Builder<Reservation>  $query
     */
    private function notEffectivelyPending(Builder $query, CarbonInterface $now): void
    {
        $query->where(function (Builder $query) use ($now): void {
            $query->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::Cancelled,
                ReservationStatus::Expired,
            ])->orWhere(fn (Builder $query) => $query
                ->where('status', ReservationStatus::Pending)
                ->where('expires_at', '<=', $now));
        });
    }
}
