<?php

namespace App\Services\Seats;

use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides whether a seat is free.
 *
 * Availability is derived from reservation_seats plus effective reservation
 * status — there is no mutable status column on seats to drift out of sync.
 * A claim on a hold whose deadline has passed counts as available straight
 * away, before the sweep has written anything down, which is what keeps the
 * map honest the second a hold expires.
 *
 * Both the per-seat map and the per-event counts run through the same SQL
 * expression, so the number next to an event can never disagree with the map
 * behind it.
 */
class SeatAvailability
{
    public const SOLD = 'sold';

    public const HELD = 'held';

    public const AVAILABLE = 'available';

    /**
     * Per-seat status for one event, ordered by seat id. One query.
     *
     * @return Collection<int, object{id: int, number: string, price: int, status: string}>
     */
    public function mapFor(Event $event, ?CarbonInterface $now = null): Collection
    {
        return $this->seatsWithActiveClaim()
            ->selectRaw(
                's.id, s.number, s.price, '.$this->statusExpression().' as status',
                [$now ?? now()],
            )
            ->where('s.event_id', $event->getKey())
            ->orderBy('s.id')
            ->get();
    }

    /**
     * Put seats_total and seats_available on each event. One query for the
     * whole collection, so a page of 20 events costs one query, not 20.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    public function attachCounts(Collection $events, ?CarbonInterface $now = null): Collection
    {
        $counts = $this->countsFor(
            $events->map(fn (Event $event): int => (int) $event->getKey())->all(),
            $now ?? now(),
        );

        return $events->each(function (Event $event) use ($counts): void {
            // An event with no seats has nothing to join to, so no row.
            $row = $counts->get((int) $event->getKey());

            $event->setAttribute('seats_total', (int) ($row->seats_total ?? 0));
            $event->setAttribute('seats_available', (int) ($row->seats_available ?? 0));
        });
    }

    /**
     * @param  list<int>  $eventIds
     * @return Collection<int, object{event_id: int, seats_total: int, seats_available: int}>
     */
    private function countsFor(array $eventIds, CarbonInterface $now): Collection
    {
        if ($eventIds === []) {
            return new Collection;
        }

        // seats_available is counted by asking the very same expression the
        // map uses, rather than by restating the rule as its own predicate.
        $available = sprintf(
            "SUM(CASE WHEN (%s) = '%s' THEN 1 ELSE 0 END)",
            $this->statusExpression(),
            self::AVAILABLE,
        );

        return $this->seatsWithActiveClaim()
            ->selectRaw("s.event_id, COUNT(*) as seats_total, {$available} as seats_available", [$now])
            ->whereIn('s.event_id', $eventIds)
            ->groupBy('s.event_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->event_id);
    }

    /**
     * Every seat of an event, left-joined to its active claim and that
     * claim's reservation.
     *
     * The join goes through reservation_seats.active_seat_id, the stored
     * generated column that holds seat_id while a claim is live and NULL once
     * it is released. It carries a UNIQUE index, so a seat matches at most one
     * row: no GROUP BY is needed to collapse duplicates, and released claims
     * drop out of the join without a separate WHERE.
     */
    private function seatsWithActiveClaim(): QueryBuilder
    {
        return DB::table('seats as s')
            ->leftJoin('reservation_seats as rs', 'rs.active_seat_id', '=', 's.id')
            ->leftJoin('reservations as r', 'r.id', '=', 'rs.reservation_id');
    }

    /**
     * The availability rule itself. Takes one binding: PHP's now().
     *
     * The `?` is bound from PHP rather than written as MySQL NOW(), so the
     * database clock never enters into it and Pest's travel() moves this
     * comparison along with everything else. The interpolated values are
     * backed-enum constants and this class's own constants — never input.
     */
    private function statusExpression(): string
    {
        return sprintf(
            "CASE WHEN r.status = '%s' THEN '%s' WHEN r.status = '%s' AND r.expires_at > ? THEN '%s' ELSE '%s' END",
            ReservationStatus::Confirmed->value,
            self::SOLD,
            ReservationStatus::Pending->value,
            self::HELD,
            self::AVAILABLE,
        );
    }
}
