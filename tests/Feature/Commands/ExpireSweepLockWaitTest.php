<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| A contended row must not take the batch down with it
|--------------------------------------------------------------------------
|
| DatabaseTruncation rather than RefreshDatabase: the rows have to be
| committed for the `mysql_locker` connection to see and lock them.
|
| The sweep is allowed to wait for locks — only the reserve hot path uses
| NOWAIT — so the guarantee here is narrower and more important: when a wait
| does time out, that one reservation is skipped and the rest of the batch
| still gets done, and it is never retried in-process.
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

it('skips the reservation it cannot lock and expires the rest', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);

    $contendedSeat = Seat::factory()->for($event)->create();
    $freeSeat = Seat::factory()->for($event)->create();

    $contended = app(CreateReservation::class)
        ->handle(User::factory()->create(), $event, [$contendedSeat->id])
        ->reservation;

    $sweepable = app(CreateReservation::class)
        ->handle(User::factory()->create(), $event, [$freeSeat->id])
        ->reservation;

    // Both holds are lapsed. travel() is no use here — DatabaseTruncation has
    // no transaction to hide the rows, but the sweep reads its own now() — so
    // the deadlines are simply written into the past.
    Reservation::query()->whereKey([$contended->id, $sweepable->id])
        ->update(['expires_at' => now()->subMinutes(5)]);

    // A second connection standing in for another request mid-flight on the
    // contended seat.
    $locker = DB::connection('mysql_locker');
    $locker->beginTransaction();
    $locker->table('seats')->where('id', $contendedSeat->id)->lockForUpdate()->get();

    // One second, so the test does not sit through the container's five.
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    $startedAt = microtime(true);

    try {
        artisan('reservations:expire')
            ->expectsOutputToContain('expired 1, skipped 1')
            ->assertSuccessful();
    } finally {
        $locker->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    $elapsed = microtime(true) - $startedAt;

    // The uncontended hold was swept despite its neighbour timing out.
    expect($sweepable->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($sweepable->fresh()->claims()->active()->count())->toBe(0);

    // The contended one was left exactly as it was, for the next run.
    expect($contended->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and($contended->fresh()->claims()->active()->count())->toBe(1);

    // One timeout, not three. A retried lock wait would cost another full
    // second per attempt, which is the behaviour the design rules out.
    expect($elapsed)->toBeLessThan(2.5);
});

it('picks the skipped reservation up on the next run', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();

    $hold = app(CreateReservation::class)
        ->handle(User::factory()->create(), $event, [$seat->id])
        ->reservation;

    Reservation::query()->whereKey($hold->id)->update(['expires_at' => now()->subMinutes(5)]);

    $locker = DB::connection('mysql_locker');
    $locker->beginTransaction();
    $locker->table('seats')->where('id', $seat->id)->lockForUpdate()->get();

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        artisan('reservations:expire')->expectsOutputToContain('expired 0, skipped 1')->assertSuccessful();
    } finally {
        $locker->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    expect($hold->fresh()->status)->toBe(ReservationStatus::Pending);

    // Nothing was lost: the contention is gone and the next minute settles it.
    artisan('reservations:expire')->expectsOutputToContain('expired 1, skipped 0')->assertSuccessful();

    expect($hold->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($hold->fresh()->claims()->active()->count())->toBe(0);
});
