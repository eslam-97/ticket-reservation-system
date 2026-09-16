<?php

namespace App\Actions\Reservations;

use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\Domain\ReservationAlreadyPendingException;
use App\Exceptions\Domain\SeatsUnavailableException;
use App\Exceptions\DomainException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Start a hold on a set of seats.
 *
 * One transaction, and the lock order is the one every other flow in the
 * system uses: seats (by id) -> reservations -> reservation_seats. Nothing is
 * ever locked before its reservation, which is what keeps this flow, the
 * webhook and the sweep from forming a cycle.
 *
 * The transaction does its own reclaiming rather than waiting for
 * reservations:expire. A hold whose deadline has passed is already expired by
 * the effective-status rule, but the two generated unique indexes see the
 * stored status, so they would reject the insert before a later reclaim could
 * help. Expiring the stale rows first is what lets a seat be retaken between
 * sweeps, and it means the write path never depends on the scheduler.
 */
class CreateReservation
{
    /** MySQL ER_LOCK_NOWAIT: a row we asked not to wait for was already locked. */
    private const LOCK_NOWAIT_FAILED = 3572;

    /** The generated index behind the one-pending-hold-per-user-per-event rule. */
    private const PENDING_USER_EVENT_INDEX = 'reservations_pending_user_event_unique';

    public function __construct(private readonly ReclaimExpiredHold $reclaimHold) {}

    /**
     * @param  array<int, int|string>  $seatIds
     */
    public function handle(User $user, Event $event, array $seatIds): CreateReservationResult
    {
        $seatIds = $this->normalise($seatIds);

        Log::withContext([
            'event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
        ]);

        // attempts: 3 retries deadlocks (1213) and nothing else. Under a total
        // lock order there should never be one; this is the belt for the day
        // someone breaks the order.
        return DB::transaction(function () use ($user, $event, $seatIds): CreateReservationResult {
            $now = now();

            $seats = $this->lockSeats($event, $seatIds);

            $reclaimable = $this->classifyActiveClaims($user, $seatIds, $now);

            $this->reclaim($reclaimable, $seatIds);

            $own = $this->resolveOwnHold($user, $event, $seatIds, $now);

            if ($own instanceof Reservation) {
                Log::withContext(['reservation_id' => $own->getKey()]);
                Log::info('Returned an existing hold for the same seats.');

                return new CreateReservationResult($own, created: false);
            }

            return new CreateReservationResult(
                $this->insert($user, $event, $seats, $seatIds, $now),
                created: true,
            );
        }, attempts: 3);
    }

    /**
     * Step 1: lock the seat rows, ordered by id, without waiting.
     *
     * Ordering by id is what stops two concurrent requests for overlapping
     * seat sets from deadlocking. NOWAIT is used only here, on the hot path: a
     * user should learn instantly that someone else is mid-reservation on
     * their seat rather than queue behind that transaction and then time out.
     *
     * @param  list<int>  $seatIds
     * @return Collection<int, Seat>
     */
    private function lockSeats(Event $event, array $seatIds): Collection
    {
        try {
            $seats = Seat::query()
                ->where('event_id', $event->getKey())
                ->whereIn('id', $seatIds)
                ->orderBy('id')
                ->lock('for update nowait')
                ->get();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === self::LOCK_NOWAIT_FAILED) {
                Log::info('Seat rows are locked by another in-flight reservation.', [
                    'seat_ids' => $seatIds,
                ]);

                throw new SeatsUnavailableException($seatIds, nowait: true);
            }

            throw $e;
        }

        // Defensive: the form request already proved every seat belongs to
        // this event, so a mismatch here means a seat vanished mid-request.
        if ($seats->count() !== count($seatIds)) {
            throw ValidationException::withMessages([
                'seat_ids' => ['One or more of those seats no longer exist for this event.'],
            ]);
        }

        return $seats;
    }

    /**
     * Step 2: find out who, if anyone, currently holds these seats.
     *
     * A plain unlocked read: MVCC never waits on row locks, and every row that
     * matters is pinned by the seat locks already held. Anything that must
     * change is locked in step 3, in order.
     *
     * @param  list<int>  $seatIds
     * @return Collection<string, Reservation> reservations to reclaim, keyed by id
     */
    private function classifyActiveClaims(User $user, array $seatIds, CarbonInterface $now): Collection
    {
        $claims = ReservationSeat::query()
            ->active()
            ->whereIn('seat_id', $seatIds)
            ->with('reservation')
            ->get();

        $conflicts = [];
        $reclaim = [];

        foreach ($claims as $claim) {
            $reservation = $claim->reservation;
            $seatId = (int) $claim->seat_id;

            if ($reservation->status === ReservationStatus::Confirmed) {
                $conflicts[] = $seatId;

                continue;
            }

            if ($reservation->status === ReservationStatus::Pending) {
                if ($reservation->expires_at > $now) {
                    // A live hold. Someone else's is a conflict; the caller's
                    // own is not, and step 4 decides what it means.
                    if ((int) $reservation->user_id !== (int) $user->getKey()) {
                        $conflicts[] = $seatId;
                    }

                    continue;
                }

                // Effectively expired, not yet swept: reclaimable.
                $reclaim[$reservation->getKey()] = $reservation;

                continue;
            }

            // An expired or cancelled reservation releases its claims when it
            // settles. Reaching here means one did not, so say so loudly and
            // then clean it up rather than refusing a seat nobody holds.
            Log::error('Active claim found on a settled reservation.', [
                'reservation_id' => $reservation->getKey(),
                'reservation_status' => $reservation->status->value,
                'seat_id' => $seatId,
            ]);

            $reclaim[$reservation->getKey()] = $reservation;
        }

        if ($conflicts !== []) {
            throw new SeatsUnavailableException($conflicts);
        }

        return new Collection($reclaim);
    }

    /**
     * Step 3: settle the stale holds so their seats come free.
     *
     * @param  Collection<string, Reservation>  $reservations
     * @param  list<int>  $seatIds
     */
    private function reclaim(Collection $reservations, array $seatIds): void
    {
        // Reservations are locked in id order, as everywhere else.
        foreach ($reservations->sortKeys() as $reservation) {
            match ($this->reclaimHold->handle($reservation->getKey())) {
                ReclaimOutcome::Expired, ReclaimOutcome::AlreadyReleased => null,

                // A late payment confirmed this hold between our unlocked
                // read and the lock. It owns the seats now.
                ReclaimOutcome::Confirmed => throw $this->seatsTakenBy($reservation, $seatIds),

                // Cannot happen while expires_at is immutable, but if it ever
                // did the seats are genuinely still held and the insert must
                // not go ahead on the strength of a stale read.
                ReclaimOutcome::StillPending => throw $this->seatsTakenBy($reservation, $seatIds),
            };
        }
    }

    /**
     * The seats of `$reservation` that this request actually wanted.
     *
     * @param  list<int>  $seatIds
     */
    private function seatsTakenBy(Reservation $reservation, array $seatIds): SeatsUnavailableException
    {
        $contested = array_values(array_intersect($reservation->seatIds(), $seatIds));

        return new SeatsUnavailableException($contested === [] ? $seatIds : $contested);
    }

    /**
     * Step 4: the one-pending-per-user rule, evaluated against what survives.
     *
     * @param  list<int>  $seatIds
     * @return Reservation|null the caller's existing hold, to be returned as-is
     */
    private function resolveOwnHold(User $user, Event $event, array $seatIds, CarbonInterface $now): ?Reservation
    {
        $own = Reservation::query()
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->where('status', ReservationStatus::Pending)
            ->first();

        if (! $own instanceof Reservation) {
            return null;
        }

        // The caller's own hold on this event has lapsed: reclaim it too, or
        // the one-pending-per-user index would reject the new row.
        if ($own->isEffectivelyExpired($now)) {
            $outcome = $this->reclaimHold->handle($own->getKey());

            if ($outcome === ReclaimOutcome::Confirmed) {
                throw $this->seatsTakenBy($own, $seatIds);
            }

            if ($outcome !== ReclaimOutcome::StillPending) {
                return null;
            }

            // Defensive: the routine says the hold is live after all, so fall
            // through and treat it as one rather than inserting a second.
            $own->refresh();
        }

        // A live hold for exactly these seats is a retry of a request whose
        // response was lost. Hand the same reservation back.
        if ($own->seatIds() === $seatIds) {
            return $own;
        }

        // Any other seat set, overlapping or not, is an explicit conflict.
        // Replacing or merging could orphan an open checkout session.
        throw new ReservationAlreadyPendingException($own->getKey());
    }

    /**
     * Step 5: insert the hold and its claims.
     *
     * @param  Collection<int, Seat>  $seats
     * @param  list<int>  $seatIds
     */
    private function insert(User $user, Event $event, Collection $seats, array $seatIds, CarbonInterface $now): Reservation
    {
        $holdEnds = $now->copy()->addMinutes((int) config('reservations.hold_minutes'));

        // Two expiry rules, one column: a hold never outlives its event, so a
        // hold started 15 minutes before curtain-up is 15 minutes long and the
        // response says so.
        $expiresAt = $holdEnds->lessThan($event->starts_at) ? $holdEnds : $event->starts_at->copy();

        try {
            $reservation = Reservation::query()->create([
                'user_id' => $user->getKey(),
                'event_id' => $event->getKey(),
                'status' => ReservationStatus::Pending,
                // Computed server-side from the seat rows we hold locks on.
                // The client never sends an amount.
                'total_amount' => (int) $seats->sum('price'),
                'currency' => $event->currency,
                'expires_at' => $expiresAt,
            ]);

            Log::withContext(['reservation_id' => $reservation->getKey()]);

            ReservationSeat::query()->insert(
                $seats->map(fn (Seat $seat): array => [
                    'reservation_id' => $reservation->getKey(),
                    'seat_id' => $seat->getKey(),
                    // Price snapshot: a later price change does not move this.
                    'unit_price' => (int) $seat->price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        } catch (UniqueConstraintViolationException $e) {
            throw $this->explain($e, $user, $event, $seatIds);
        }

        Log::info('Reservation created.', [
            'seat_ids' => $seatIds,
            'total_amount' => $reservation->total_amount,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $reservation;
    }

    /**
     * Turn a unique-constraint violation into the right domain exception.
     *
     * The two generated indexes fail for completely different reasons, and
     * collapsing them into one answer is how a client loses the information
     * it needs to recover.
     *
     * @param  list<int>  $seatIds
     */
    private function explain(UniqueConstraintViolationException $e, User $user, Event $event, array $seatIds): DomainException
    {
        // One pending hold per user per event. Nothing locks this: step 4
        // reads it unlocked, so two concurrent requests from the same user
        // for *disjoint* seat sets both see no existing hold and both insert.
        // The index serialises them, which makes this an ordinary race rather
        // than a bug — and the loser needs the winner's id to act on.
        //
        // Overlapping seat sets never reach here: they contend on the same
        // seat lock and the loser gets a NOWAIT failure instead.
        if (str_contains($e->getMessage(), self::PENDING_USER_EVENT_INDEX)) {
            // A locking read, and that is not incidental: we are still inside
            // the transaction, whose REPEATABLE READ snapshot predates the
            // winner's commit, so a plain read finds nothing at all. Locking
            // reads see the latest committed row instead. Seats are already
            // locked above, so seats -> reservations still holds.
            $existing = Reservation::query()
                ->where('user_id', $user->getKey())
                ->where('event_id', $event->getKey())
                ->where('status', ReservationStatus::Pending)
                ->lockForUpdate()
                ->first();

            Log::info('Lost the one-pending-per-user race to a concurrent request.', [
                'reservation_id' => $existing?->getKey(),
            ]);

            if ($existing instanceof Reservation) {
                return new ReservationAlreadyPendingException((string) $existing->getKey());
            }

            // The winner settled between the violation and this read, so
            // there is nothing to point the client at.
            return new SeatsUnavailableException($seatIds);
        }

        // One active claim per seat. The seat locks plus the inline reclaim
        // above mean this one genuinely is unreachable without a bug in the
        // lock path, so it stays loud.
        Log::error('Unique constraint fired after inline reclaim; this is a bug in the lock path.', [
            'user_id' => $user->getKey(),
            'event_id' => $event->getKey(),
            'seat_ids' => $seatIds,
            'constraint' => $e->getMessage(),
        ]);

        return new SeatsUnavailableException($seatIds);
    }

    /**
     * @param  array<int, int|string>  $seatIds
     * @return list<int>
     */
    private function normalise(array $seatIds): array
    {
        return collect($seatIds)->map(intval(...))->unique()->sort()->values()->all();
    }
}
