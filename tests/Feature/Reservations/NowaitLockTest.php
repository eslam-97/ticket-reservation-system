<?php

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| NOWAIT on the reserve hot path
|--------------------------------------------------------------------------
|
| DatabaseTruncation, not RefreshDatabase: RefreshDatabase wraps each test in
| a transaction that is never committed, so the `mysql_locker` connection
| below would not be able to see — let alone lock — the rows this test sets
| up. Truncating between tests is the price of having real committed rows.
|
| The whole point of NOWAIT is what does *not* happen: with
| innodb-lock-wait-timeout=5 in Compose, a request that waited would take at
| least five seconds. The wall-clock assertion at the end is the real
| evidence that the request failed instantly instead of queueing.
|
*/

uses(DatabaseTruncation::class);

/*
 * DatabaseTruncation wipes the tables *before* each test in this file, but
 * nothing clears them after the last one — and unlike RefreshDatabase there is
 * no transaction to roll back, because the whole point here is that the rows
 * are committed. Without this, everything that runs after this file would
 * start against leftovers.
 */
afterEach(function () {
    $this->truncateDatabaseTables();
});

it('fails instantly with 409 while another connection holds the seat row', function () {
    $startedAt = microtime(true);

    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();
    $user = User::factory()->create();

    // A second, genuinely separate connection standing in for another
    // request that is mid-reservation on this seat.
    $locker = DB::connection('mysql_locker');
    $locker->beginTransaction();
    $locker->table('seats')->where('id', $seat->id)->lockForUpdate()->get();

    try {
        $blocked = $this->actingAs($user)->postJson('/api/v1/reservations', [
            'event_id' => $event->id,
            'seat_ids' => [$seat->id],
        ]);

        $blocked->assertStatus(409)
            ->assertJsonPath('error.code', 'seats_unavailable')
            ->assertJsonPath('error.details.seat_ids', [$seat->id]);

        // The message is what separates a NOWAIT failure from a seat that is
        // simply taken: both are 409 seats_unavailable, but only this one is
        // worth retrying immediately.
        expect($blocked->json('error.message'))->toContain('try again');

        // Nothing was written while the row was contended.
        expect(Reservation::query()->count())->toBe(0);
    } finally {
        $locker->rollBack();
    }

    // With the lock gone the very same request succeeds.
    $this->actingAs($user)->postJson('/api/v1/reservations', [
        'event_id' => $event->id,
        'seat_ids' => [$seat->id],
    ])->assertCreated();

    expect(Reservation::query()->count())->toBe(1);

    // innodb_lock_wait_timeout is 5s; anything that waited could not finish
    // this fast. Five seconds of margin over the two requests plus setup.
    expect(microtime(true) - $startedAt)->toBeLessThan(3.0);
});

it('does not take the nowait path when the seat is merely claimed', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();

    $holder = User::factory()->create();

    $this->actingAs($holder)->postJson('/api/v1/reservations', [
        'event_id' => $event->id,
        'seat_ids' => [$seat->id],
    ])->assertCreated();

    $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/reservations', [
        'event_id' => $event->id,
        'seat_ids' => [$seat->id],
    ]);

    // Same status and code as the contended case, different message: this one
    // will not come free on a retry, so the client is not told to try again.
    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'seats_unavailable')
        ->assertJsonPath('error.details.seat_ids', [$seat->id]);

    expect($response->json('error.message'))->not->toContain('try again');
});
