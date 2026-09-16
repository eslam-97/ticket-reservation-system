<?php

use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/**
 * An event far enough out that holds get their full 30 minutes.
 */
function anEvent(int $seats = 10, ?int $startsInMinutes = null): Event
{
    $event = Event::factory()->create([
        'starts_at' => now()->addMinutes($startsInMinutes ?? 60 * 24 * 7),
    ]);

    Seat::factory()->count($seats)->for($event)->create();

    return $event;
}

/**
 * @return Collection<int, Seat>
 */
function seatsOf(Event $event): Collection
{
    return $event->seats()->orderBy('id')->get();
}

/**
 * Give `$user` a live hold on `$seatIds` without going through the endpoint.
 */
function holdFor(User $user, Event $event, array $seatIds, ?string $status = null): Reservation
{
    $seats = Seat::query()->whereIn('id', $seatIds)->orderBy('id')->get();

    $reservation = Reservation::query()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'status' => $status ?? ReservationStatus::Pending->value,
        'total_amount' => (int) $seats->sum('price'),
        'currency' => $event->currency,
        'expires_at' => now()->addMinutes(30),
    ]);

    foreach ($seats as $seat) {
        ReservationSeat::query()->create([
            'reservation_id' => $reservation->id,
            'seat_id' => $seat->id,
            'unit_price' => $seat->price,
        ]);
    }

    return $reservation;
}

function reserve(User $user, Event $event, array $seatIds)
{
    return test()->actingAs($user)->postJson('/api/v1/reservations', [
        'event_id' => $event->id,
        'seat_ids' => $seatIds,
    ]);
}

/*
|--------------------------------------------------------------------------
| 1. Happy path
|--------------------------------------------------------------------------
*/

it('creates a hold with a deadline, a frozen total and price snapshots', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seats = seatsOf($event)->take(3);
    $seatIds = $seats->pluck('id')->all();

    $response = reserve($user, $event, $seatIds)->assertCreated();

    $response->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.stored_status', 'pending')
        ->assertJsonPath('data.currency', $event->currency)
        ->assertJsonPath('data.total_amount', (int) $seats->sum('price'))
        ->assertJsonPath('data.event.id', $event->id);

    $reservation = Reservation::query()->sole();

    // The hold runs for the configured 30 minutes, measured from server time.
    expect($reservation->expires_at->diffInSeconds(now()->addMinutes(30), absolute: true))
        ->toBeLessThanOrEqual(5)
        ->and($reservation->total_amount)->toBe((int) $seats->sum('price'))
        ->and($reservation->seatIds())->toBe(collect($seatIds)->sort()->values()->all());

    // Every claim carries the seat's price at the moment of the hold.
    $reservation->claims->each(
        fn (ReservationSeat $claim) => expect($claim->unit_price)->toBe($claim->seat->price)
            ->and($claim->released_at)->toBeNull()
    );

    expect($response->json('data.expires_at'))->toBe($reservation->expires_at->utc()->toIso8601String());
});

/*
|--------------------------------------------------------------------------
| 2. Retry with the identical seat set
|--------------------------------------------------------------------------
*/

it('returns the same reservation with 200 when the identical set is requested again', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seatIds = seatsOf($event)->take(2)->pluck('id')->all();

    $first = reserve($user, $event, $seatIds)->assertCreated();

    // A client whose response was lost retries; it must get its hold back,
    // not a conflict over its own seats.
    $second = reserve($user, $event, $seatIds)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Reservation::query()->count())->toBe(1)
        ->and(ReservationSeat::query()->count())->toBe(2);
});

it('ignores the order the seat ids arrive in when matching an existing hold', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seatIds = seatsOf($event)->take(3)->pluck('id')->all();

    $first = reserve($user, $event, $seatIds)->assertCreated();
    $second = reserve($user, $event, array_reverse($seatIds))->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
});

/*
|--------------------------------------------------------------------------
| 3. One pending reservation per user per event
|--------------------------------------------------------------------------
*/

it('refuses a different seat set while a hold is live', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seats = seatsOf($event);

    $first = reserve($user, $event, $seats->take(2)->pluck('id')->all())->assertCreated();

    reserve($user, $event, $seats->slice(4, 2)->pluck('id')->all())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'reservation_already_pending')
        ->assertJsonPath('error.details.reservation_id', $first->json('data.id'));

    expect(Reservation::query()->count())->toBe(1);
});

it('refuses a partially overlapping seat set while a hold is live', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seats = seatsOf($event);

    $first = reserve($user, $event, $seats->take(2)->pluck('id')->all())->assertCreated();

    // Seat 1 is the caller's own, seat 3 is free: still a conflict, because
    // the alternative is silently replacing or merging the hold.
    reserve($user, $event, [$seats[0]->id, $seats[2]->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'reservation_already_pending')
        ->assertJsonPath('error.details.reservation_id', $first->json('data.id'));

    expect(Reservation::query()->count())->toBe(1);
});

it('allows a hold on a different event while one is live', function () {
    $user = User::factory()->create();
    $first = anEvent();
    $second = anEvent();

    reserve($user, $first, seatsOf($first)->take(1)->pluck('id')->all())->assertCreated();
    reserve($user, $second, seatsOf($second)->take(1)->pluck('id')->all())->assertCreated();

    expect(Reservation::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 4 & 5. Seats another user holds or has bought
|--------------------------------------------------------------------------
*/

it('refuses a seat another user is holding, naming just that seat', function () {
    $event = anEvent();
    $seats = seatsOf($event);
    $taken = $seats[1];

    holdFor(User::factory()->create(), $event, [$taken->id]);

    reserve(User::factory()->create(), $event, [$seats[0]->id, $taken->id, $seats[2]->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'seats_unavailable')
        ->assertJsonPath('error.details.seat_ids', [$taken->id]);

    // Nothing was written for the loser.
    expect(Reservation::query()->count())->toBe(1);
});

it('refuses a seat that has been sold', function () {
    $event = anEvent();
    $seats = seatsOf($event);
    $sold = $seats[0];

    holdFor(User::factory()->create(), $event, [$sold->id], ReservationStatus::Confirmed->value);

    reserve(User::factory()->create(), $event, [$sold->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'seats_unavailable')
        ->assertJsonPath('error.details.seat_ids', [$sold->id]);
});

it('keeps a sold seat unavailable even after the hold deadline passes', function () {
    $event = anEvent(startsInMinutes: 60 * 24 * 7);
    $seats = seatsOf($event);
    $sold = $seats[0];

    holdFor(User::factory()->create(), $event, [$sold->id], ReservationStatus::Confirmed->value);

    travel(31)->minutes();

    // expires_at is meaningless once a reservation is confirmed.
    reserve(User::factory()->create(), $event, [$sold->id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'seats_unavailable');
});

/*
|--------------------------------------------------------------------------
| 6 & 7. Inline reclaim, with no scheduler involved
|--------------------------------------------------------------------------
*/

it('reclaims another user\'s lapsed hold without any command running', function () {
    $event = anEvent(startsInMinutes: 60 * 24 * 7);
    $seat = seatsOf($event)[4];

    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $aliceHold = holdFor($alice, $event, [$seat->id]);

    travel(31)->minutes();

    // No reservations:expire run: the row still says pending and its claim is
    // still active at the moment Bob asks.
    expect($aliceHold->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and(ReservationSeat::query()->active()->where('seat_id', $seat->id)->count())->toBe(1);

    $response = reserve($bob, $event, [$seat->id])->assertCreated();

    $aliceHold->refresh();

    expect($aliceHold->status)->toBe(ReservationStatus::Expired)
        ->and($aliceHold->claims()->active()->count())->toBe(0)
        ->and($aliceHold->claims()->first()->released_at)->not->toBeNull();

    $bobHold = Reservation::query()->whereKey($response->json('data.id'))->sole();

    expect($bobHold->claims()->active()->count())->toBe(1)
        ->and($bobHold->seatIds())->toBe([$seat->id]);
});

it('reclaims the caller\'s own lapsed hold instead of calling it a conflict', function () {
    $alice = User::factory()->create();
    $event = anEvent(startsInMinutes: 60 * 24 * 7);
    $seats = seatsOf($event);

    $old = holdFor($alice, $event, [$seats[0]->id, $seats[1]->id]);

    travel(31)->minutes();

    // Different seats, but the old hold has lapsed, so this is not the
    // one-pending-per-user case.
    $response = reserve($alice, $event, [$seats[2]->id, $seats[3]->id])->assertCreated();

    $old->refresh();

    expect($old->status)->toBe(ReservationStatus::Expired)
        ->and($old->claims()->active()->count())->toBe(0);

    $new = Reservation::query()->whereKey($response->json('data.id'))->sole();

    expect($new->seatIds())->toBe([$seats[2]->id, $seats[3]->id])
        ->and(Reservation::query()->count())->toBe(2);
});

it('lets the caller retake the very seats their own lapsed hold had', function () {
    $alice = User::factory()->create();
    $event = anEvent(startsInMinutes: 60 * 24 * 7);
    $seatIds = seatsOf($event)->take(2)->pluck('id')->all();

    $old = holdFor($alice, $event, $seatIds);

    travel(31)->minutes();

    reserve($alice, $event, $seatIds)->assertCreated();

    expect($old->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and(ReservationSeat::query()->active()->whereIn('seat_id', $seatIds)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 8. The expiry clamp
|--------------------------------------------------------------------------
*/

it('clamps the deadline to the event start when the event is closer than the hold', function () {
    $user = User::factory()->create();
    $event = anEvent(startsInMinutes: 12);

    $response = reserve($user, $event, seatsOf($event)->take(1)->pluck('id')->all())->assertCreated();

    $reservation = Reservation::query()->sole();

    // A hold started 12 minutes before curtain-up is 12 minutes long, and the
    // response says so rather than promising 30.
    expect($reservation->expires_at->diffInSeconds($event->starts_at, absolute: true))
        ->toBeLessThanOrEqual(1)
        ->and($response->json('data.expires_at'))
        ->toBe($reservation->expires_at->utc()->toIso8601String());
});

/*
|--------------------------------------------------------------------------
| 9. Input rules
|--------------------------------------------------------------------------
*/

it('refuses more than the configured maximum number of seats', function () {
    $user = User::factory()->create();
    $event = anEvent(seats: 12);

    reserve($user, $event, seatsOf($event)->take(11)->pluck('id')->all())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['seat_ids']]]]);

    expect(Reservation::query()->count())->toBe(0);
});

it('refuses duplicate seat ids', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seat = seatsOf($event)->first();

    reserve($user, $event, [$seat->id, $seat->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['seat_ids.0']]]]);
});

it('refuses a seat belonging to another event', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $other = anEvent();
    $stranger = seatsOf($other)->first();

    reserve($user, $event, [seatsOf($event)->first()->id, $stranger->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.errors.seat_ids', ["Seat {$stranger->id} does not belong to this event."]);
});

it('refuses an event that has already started', function () {
    $user = User::factory()->create();
    $event = anEvent(startsInMinutes: 60);

    travel(61)->minutes();

    reserve($user, $event, seatsOf($event)->take(1)->pluck('id')->all())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.errors.event_id', ['Sales for this event have closed.']);
});

it('refuses an empty seat array', function () {
    $user = User::factory()->create();
    $event = anEvent();

    reserve($user, $event, [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['seat_ids']]]]);
});

it('refuses an unknown event', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/reservations', ['event_id' => 999999, 'seat_ids' => [1]])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['event_id']]]]);
});

it('requires authentication', function () {
    $event = anEvent();

    $this->postJson('/api/v1/reservations', [
        'event_id' => $event->id,
        'seat_ids' => seatsOf($event)->take(1)->pluck('id')->all(),
    ])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

/*
|--------------------------------------------------------------------------
| 10. Reading a reservation back
|--------------------------------------------------------------------------
*/

it('shows the owner their reservation', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seatIds = seatsOf($event)->take(2)->pluck('id')->all();

    $id = reserve($user, $event, $seatIds)->assertCreated()->json('data.id');

    $response = $this->actingAs($user)->getJson("/api/v1/reservations/{$id}")->assertOk();

    expect(array_keys($response->json('data')))->toEqualCanonicalizing([
        'id', 'status', 'stored_status', 'expires_at', 'total_amount',
        'currency', 'event', 'seats', 'payments', 'created_at',
    ]);

    $response->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.payments', [])
        ->assertJsonCount(2, 'data.seats');

    expect(array_keys($response->json('data.seats.0')))
        ->toEqualCanonicalizing(['id', 'number', 'unit_price', 'released_at']);
});

it('reports the effective status, not the stored one, once the hold lapses', function () {
    $user = User::factory()->create();
    $event = anEvent(startsInMinutes: 60 * 24 * 7);

    $id = reserve($user, $event, seatsOf($event)->take(1)->pluck('id')->all())
        ->assertCreated()->json('data.id');

    travel(31)->minutes();

    $this->actingAs($user)->getJson("/api/v1/reservations/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.stored_status', 'pending');
});

it('refuses to show someone else\'s reservation', function () {
    $owner = User::factory()->create();
    $event = anEvent();

    $id = reserve($owner, $event, seatsOf($event)->take(1)->pluck('id')->all())
        ->assertCreated()->json('data.id');

    $this->actingAs(User::factory()->create())
        ->getJson("/api/v1/reservations/{$id}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'This action is unauthorized.');
});

it('returns the 404 envelope for an unknown reservation id', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/reservations/'.strtolower((string) Str::ulid()))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'Resource not found.');
});

/*
|--------------------------------------------------------------------------
| 11. The database is the second line of defence
|--------------------------------------------------------------------------
*/

it('refuses a duplicate active claim at the database level', function () {
    $user = User::factory()->create();
    $event = anEvent();
    $seat = seatsOf($event)->first();

    $held = holdFor($user, $event, [$seat->id]);
    $other = holdFor(User::factory()->create(), $event, []);

    // Straight past the action and its locks: the generated unique index on
    // active_seat_id still refuses to let one seat be claimed twice.
    expect(fn () => DB::table('reservation_seats')->insert([
        'reservation_id' => $other->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(ReservationSeat::query()->active()->where('seat_id', $seat->id)->count())->toBe(1)
        ->and($held->claims()->active()->count())->toBe(1);
});
