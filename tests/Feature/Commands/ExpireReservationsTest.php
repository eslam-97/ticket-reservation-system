<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\ReclaimOutcome;
use App\Console\Commands\ExpireReservations;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/**
 * A hold on fresh seats of its own event.
 */
function holdFor2(int $seats = 2, ?Event $event = null): Reservation
{
    $event ??= Event::factory()->create(['starts_at' => now()->addWeek()]);

    $seatModels = Seat::factory()->count($seats)->for($event)->create();

    return app(CreateReservation::class)
        ->handle(User::factory()->create(), $event, $seatModels->pluck('id')->all())
        ->reservation;
}

it('expires a lapsed hold and releases its seats', function () {
    $hold = holdFor2();

    travel(31)->minutes();

    artisan('reservations:expire')
        ->expectsOutputToContain('expired 1, skipped 0')
        ->assertSuccessful();

    $hold->refresh();

    expect($hold->status)->toBe(ReservationStatus::Expired)
        ->and($hold->claims()->active()->count())->toBe(0)
        ->and($hold->claims()->count())->toBe(2);
});

it('leaves a live hold alone', function () {
    $hold = holdFor2();

    artisan('reservations:expire')
        ->expectsOutputToContain('expired 0, skipped 0')
        ->assertSuccessful();

    expect($hold->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($hold->claims()->active()->count())->toBe(2);
});

it('leaves a confirmed reservation alone however old', function () {
    $hold = holdFor2();
    $hold->transitionTo(ReservationStatus::Confirmed);

    travel(31)->minutes();

    // Not even a candidate: effectivelyExpired only matches stored `pending`.
    artisan('reservations:expire')
        ->expectsOutputToContain('expired 0, skipped 0')
        ->assertSuccessful();

    expect($hold->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($hold->claims()->active()->count())->toBe(2);
});

it('leaves a cancelled reservation alone', function () {
    $hold = holdFor2();
    $hold->transitionTo(ReservationStatus::Cancelled);

    travel(31)->minutes();

    artisan('reservations:expire')->assertSuccessful();

    expect($hold->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('sweeps the whole batch, not just the first row', function () {
    $holds = collect(range(1, 3))->map(fn () => holdFor2(1));

    travel(31)->minutes();

    artisan('reservations:expire')
        ->expectsOutputToContain('expired 3, skipped 0')
        ->assertSuccessful();

    $holds->each(fn (Reservation $hold) => expect($hold->refresh()->status)->toBe(ReservationStatus::Expired));
});

it('is idempotent: a second run finds nothing left to do', function () {
    holdFor2();

    travel(31)->minutes();

    artisan('reservations:expire')->expectsOutputToContain('expired 1, skipped 0')->assertSuccessful();
    artisan('reservations:expire')->expectsOutputToContain('expired 0, skipped 0')->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Losing the race, under the lock
|--------------------------------------------------------------------------
*/

it('does not touch a reservation confirmed between the candidate read and the lock', function () {
    $hold = holdFor2();

    travel(31)->minutes();

    // Stand in for a late webhook that confirmed this hold after the sweep
    // listed it as a candidate. The per-reservation method is called with a
    // row that is already confirmed, which is exactly what the re-read under
    // the lock would find.
    $hold->transitionTo(ReservationStatus::Confirmed);

    $outcome = app(ExpireReservations::class)->sweepOne((string) $hold->id);

    // Reported, not acted on: whoever committed first wins.
    expect($outcome)->toBe(ReclaimOutcome::Confirmed);

    $hold->refresh();

    expect($hold->status)->toBe(ReservationStatus::Confirmed)
        ->and($hold->claims()->active()->count())->toBe(2);
});

it('counts a reservation the sweep found already settled as expired', function () {
    $hold = holdFor2();

    travel(31)->minutes();

    // Already expired but still holding a seat: the sweep releases the
    // straggler and counts it, rather than calling it skipped.
    $hold->transitionTo(ReservationStatus::Expired);

    expect($hold->claims()->active()->count())->toBe(2);

    $outcome = app(ExpireReservations::class)->sweepOne((string) $hold->id);

    expect($outcome)->toBe(ReclaimOutcome::AlreadyReleased)
        ->and($hold->refresh()->claims()->active()->count())->toBe(0);
});

it('reports a hold that became live again as skipped', function () {
    $hold = holdFor2();

    // Never lapsed, so the routine refuses to touch it.
    expect(app(ExpireReservations::class)->sweepOne((string) $hold->id))
        ->toBe(ReclaimOutcome::StillPending);

    expect($hold->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($hold->claims()->active()->count())->toBe(2);
});
