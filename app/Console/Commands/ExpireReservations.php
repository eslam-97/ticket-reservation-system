<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ReclaimExpiredHold;
use App\Actions\Reservations\ReclaimOutcome;
use App\Models\Reservation;
use App\Models\Seat;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Write down what the effective-status rule already says.
 *
 * This sweep materialises expiry; it does not cause it. Every read and every
 * guard already treats a pending hold past its deadline as expired, and the
 * reserve path reclaims stale holds inline, so a skipped or crashed run costs
 * nothing but a little tidiness. That is what makes it safe to skip a
 * contended row and come back next minute.
 *
 * It is still part of the concurrency protocol rather than an observer of it:
 * it changes reservation state, so it takes the same locks in the same order
 * as everything else — seats (by id) then the reservation — and delegates the
 * per-reservation work to the routine the reserve path uses, so the two
 * cannot drift.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire reservations whose hold has lapsed and release their seats';

    /** MySQL ER_LOCK_WAIT_TIMEOUT. */
    private const LOCK_WAIT_TIMEOUT = 1205;

    public function __construct(private readonly ReclaimExpiredHold $reclaim)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Candidates are read unlocked. Anything that changes underneath us is
        // caught by the re-read under lock, so a stale list is harmless.
        $ids = Reservation::query()
            ->effectivelyExpired(now())
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $outcome = $this->sweepOne((string) $id);

            if ($outcome === null) {
                $skipped++;

                continue;
            }

            if ($outcome === ReclaimOutcome::Expired) {
                $expired++;

                continue;
            }

            if ($outcome === ReclaimOutcome::AlreadyReleased) {
                // Someone settled it between the candidate read and the lock;
                // any stragglers it still held have now been released.
                $expired++;

                Log::info('Sweep found a reservation already settled.', ['reservation_id' => $id]);

                continue;
            }

            // StillPending or Confirmed: not ours to touch. A late payment
            // confirming at 30:00 while this ran at 30:01 simply wins.
            $skipped++;

            Log::info('Sweep left a reservation alone.', [
                'reservation_id' => $id,
                'outcome' => $outcome->name,
            ]);
        }

        $this->line("expired {$expired}, skipped {$skipped}");

        return self::SUCCESS;
    }

    /**
     * One reservation, one small transaction.
     *
     * @return ReclaimOutcome|null null when the row was contended and skipped
     */
    public function sweepOne(string $reservationId): ?ReclaimOutcome
    {
        try {
            return DB::transaction(function () use ($reservationId): ReclaimOutcome {
                // The seat set never changes after creation, so reading it
                // unlocked is safe — and we need it before we can lock seats
                // first, as the order requires.
                $seatIds = Reservation::query()->whereKey($reservationId)->first()?->seatIds() ?? [];

                if ($seatIds !== []) {
                    // Waiting locks here, not NOWAIT: only the reserve hot
                    // path refuses to queue. A short innodb_lock_wait_timeout
                    // bounds how long this can block.
                    Seat::query()->whereIn('id', $seatIds)->orderBy('id')->lockForUpdate()->get();
                }

                return $this->reclaim->handle($reservationId);
            }, attempts: 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::LOCK_WAIT_TIMEOUT) {
                throw $e;
            }

            // Not retried in-process: this row already cost us the full
            // timeout, and it will still be expired next minute. The rest of
            // the batch is unaffected.
            Log::warning('Sweep timed out waiting for a lock; skipping this reservation.', [
                'reservation_id' => $reservationId,
            ]);

            return null;
        }
    }
}
