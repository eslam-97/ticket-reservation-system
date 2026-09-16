<?php

use App\Domain\Reservations\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Effective status
|--------------------------------------------------------------------------
|
| The stored status lags real time between sweeps. These cases pin the rule
| that closes that gap: a pending hold past its deadline is expired for every
| read and every guard, and the clock driving that decision is PHP's.
|
*/

it('reports a stored-pending hold past its deadline as expired', function () {
    $live = Reservation::factory()->create(['expires_at' => now()->addMinutes(10)]);
    $stale = Reservation::factory()->create(['expires_at' => now()->subMinute()]);

    expect($live->status)->toBe(ReservationStatus::Pending)
        ->and($live->effectiveStatus())->toBe(ReservationStatus::Pending)
        ->and($live->isEffectivelyPending())->toBeTrue()
        ->and($live->isEffectivelyExpired())->toBeFalse();

    // Stored status still says pending — the sweep has not run — but every
    // guard must already treat this hold as gone.
    expect($stale->status)->toBe(ReservationStatus::Pending)
        ->and($stale->effectiveStatus())->toBe(ReservationStatus::Expired)
        ->and($stale->isEffectivelyExpired())->toBeTrue()
        ->and($stale->isEffectivelyPending())->toBeFalse();
});

it('treats the deadline itself as already expired', function () {
    $now = now();
    $reservation = Reservation::factory()->create(['expires_at' => $now]);

    // expires_at <= now, so the boundary belongs to expired.
    expect($reservation->effectiveStatus($now))->toBe(ReservationStatus::Expired);
});

it('leaves a settled status alone however old the deadline', function () {
    $cancelled = Reservation::factory()
        ->withStatus(ReservationStatus::Cancelled)
        ->create(['expires_at' => now()->subDay()]);

    $confirmed = Reservation::factory()
        ->withStatus(ReservationStatus::Confirmed)
        ->create(['expires_at' => now()->subDay()]);

    expect($cancelled->effectiveStatus())->toBe(ReservationStatus::Cancelled)
        ->and($confirmed->effectiveStatus())->toBe(ReservationStatus::Confirmed);
});

it('flips the scopes around an injected now', function () {
    Reservation::factory()->create(['expires_at' => now()->addMinutes(10)]);

    expect(Reservation::query()->effectivelyPending()->count())->toBe(1)
        ->and(Reservation::query()->effectivelyExpired()->count())->toBe(0);

    $afterTheDeadline = now()->addMinutes(11);

    expect(Reservation::query()->effectivelyPending($afterTheDeadline)->count())->toBe(0)
        ->and(Reservation::query()->effectivelyExpired($afterTheDeadline)->count())->toBe(1);
});

it('binds now() from PHP, so travel() moves the scopes', function () {
    Reservation::factory()->create(['expires_at' => now()->addMinutes(10)]);

    expect(Reservation::query()->effectivelyPending()->count())->toBe(1);

    // travel() moves PHP's clock only. If these scopes compared against MySQL
    // NOW() the database would be none the wiser and the hold would still
    // read as live — which is exactly the bug this assertion exists to catch.
    travel(11)->minutes();

    expect(Reservation::query()->effectivelyPending()->count())->toBe(0)
        ->and(Reservation::query()->effectivelyExpired()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Seat set and price snapshots
|--------------------------------------------------------------------------
*/

it('returns every seat id in ascending order, released claims included', function () {
    $reservation = Reservation::factory()->withSeats(3)->create()->refresh();

    $expected = $reservation->claims()->pluck('seat_id')->map(intval(...))->sort()->values()->all();

    expect($reservation->seatIds())->toBe($expected)->toHaveCount(3);

    $reservation->claims()->orderBy('seat_id')->first()->update(['released_at' => now()]);

    // A reservation's seat set is immutable after creation, so releasing a
    // claim must not shrink what seatIds() reports.
    expect($reservation->seatIds())->toBe($expected)
        ->and($reservation->claims()->active()->count())->toBe(2);
});

it('snapshots total_amount as the sum of the claims unit prices', function () {
    $reservation = Reservation::factory()->withSeats(3)->create()->refresh();

    expect($reservation->claims)->toHaveCount(3)
        ->and($reservation->total_amount)->toBeGreaterThan(0)
        ->and($reservation->total_amount)->toBe((int) $reservation->claims()->sum('unit_price'));

    // Each snapshot is the seat's price at the moment of the hold.
    $reservation->claims->each(
        fn ($claim) => expect($claim->unit_price)->toBe($claim->seat->price)
    );
});
