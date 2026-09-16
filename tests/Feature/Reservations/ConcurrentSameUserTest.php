<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\Domain\ReservationAlreadyPendingException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

/*
 * DatabaseTruncation cleans before each of its tests and never after the
 * last, and there is no transaction to roll back because the rows here are
 * deliberately committed. Without this, they leak into whatever sorts next.
 */
afterEach(function () {
    $this->truncateDatabaseTables();
});

/*
|--------------------------------------------------------------------------
| Two concurrent holds by one user, on disjoint seats
|--------------------------------------------------------------------------
|
| Step 4 reads the caller's existing hold *unlocked*, so two requests from the
| same user for seat sets that share no seat never meet: they lock different
| rows, they both see no existing hold, and they both reach the insert. The
| pending_user_event index is what serialises them.
|
| The loser must be told `reservation_already_pending` with the winner's id —
| that id is the whole of the documented recovery path, since the client is
| expected to continue or DELETE the hold that is in the way. Reporting
| `seats_unavailable` instead names seats that are perfectly free and gives
| the client nothing to act on.
|
| Overlapping seat sets never get here: they contend on a shared seat lock and
| the loser takes the NOWAIT path instead.
|
*/

/**
 * Commit a competing hold from a second connection at the exact moment the
 * flow has already read — and found nothing.
 *
 * This is the only way to stage the race single-threaded. Inserting the row
 * up front would not do it: the unlocked read would simply find it and take
 * the ordinary one-pending-per-user branch, which is a different code path
 * and was already covered.
 *
 * @return string the winning reservation's id
 */
function commitRivalHoldAfterTheLookup(User $user, Event $event): string
{
    $winnerId = strtolower((string) Str::ulid());
    $done = false;

    DB::listen(function ($query) use (&$done, $winnerId, $user, $event): void {
        if ($done) {
            return;
        }

        // Step 4's lookup: the caller's pending hold on this event, read
        // without a lock. Step 3's reads carry `for update`, and step 2's
        // are against reservation_seats, so this matches only the one.
        $isTheLookup = str_contains($query->sql, 'from `reservations`')
            && str_contains($query->sql, '`user_id` = ?')
            && ! str_contains($query->sql, 'for update');

        if (! $isTheLookup) {
            return;
        }

        $done = true;

        // A separate connection, so this commits immediately and the
        // in-flight transaction cannot see it — exactly like the other
        // request having committed a moment ago.
        DB::connection('mysql_locker')->table('reservations')->insert([
            'id' => $winnerId,
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => ReservationStatus::Pending->value,
            'total_amount' => 50000,
            'currency' => $event->currency,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $winnerId;
}

it('tells the loser of a same-user race which reservation is in the way', function () {
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seats = Seat::factory()->count(4)->for($event)->create();
    $alice = User::factory()->create();

    $winnerId = commitRivalHoldAfterTheLookup($alice, $event);

    try {
        app(CreateReservation::class)->handle($alice, $event, [$seats[2]->id, $seats[3]->id]);

        $this->fail('Expected the one-pending-per-user index to refuse this.');
    } catch (ReservationAlreadyPendingException $e) {
        expect($e->code())->toBe('reservation_already_pending')
            ->and($e->status())->toBe(409)
            // The id the client needs in order to recover.
            ->and($e->details())->toBe(['reservation_id' => $winnerId]);
    }

    // The loser wrote nothing: one hold, and it is the winner's.
    expect(Reservation::query()->count())->toBe(1)
        ->and(Reservation::query()->sole()->id)->toBe($winnerId);
});

it('reports it over HTTP as a 409 naming the reservation, not the seats', function () {
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seats = Seat::factory()->count(4)->for($event)->create();
    $alice = User::factory()->create();

    $winnerId = commitRivalHoldAfterTheLookup($alice, $event);

    $this->actingAs($alice)
        ->postJson('/api/v1/reservations', [
            'event_id' => $event->id,
            'seat_ids' => [$seats[2]->id, $seats[3]->id],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'reservation_already_pending')
        ->assertJsonPath('error.details.reservation_id', $winnerId)
        // Naming free seats here would send the client chasing the wrong fix.
        ->assertJsonMissingPath('error.details.seat_ids');
});

it('still calls a genuine seat clash seats_unavailable', function () {
    // The other half of the split catch must keep its old behaviour: a seat
    // really taken by someone else is a seat problem, not a hold problem.
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seat = Seat::factory()->for($event)->create();

    app(CreateReservation::class)->handle(User::factory()->create(), $event, [$seat->id]);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/reservations', [
            'event_id' => $event->id,
            'seat_ids' => [$seat->id],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'seats_unavailable')
        ->assertJsonPath('error.details.seat_ids', [$seat->id]);
});
