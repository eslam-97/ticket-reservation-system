<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The scheduler is down
|--------------------------------------------------------------------------
|
| Section 9 gives this failure no owner, because nothing owns it: lazy expiry
| keeps availability correct and the sweep catches up when it returns. These
| run *no* commands at all — not the sweep, not reconcile — and assert the
| system still behaves, which is the only way to show correctness does not
| depend on the scheduler.
|
*/

beforeEach(function () {
    $this->alice = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $this->seats = Seat::factory()->count(3)->for($this->event)->create();
});

it('keeps the seat map correct with the scheduler never running', function () {
    app(CreateReservation::class)->handle($this->alice, $this->event, [$this->seats[0]->id]);

    $held = collect($this->getJson("/api/v1/events/{$this->event->id}/seats")->json('data'))
        ->firstWhere('id', $this->seats[0]->id);

    expect($held['status'])->toBe('held');

    travel(31)->minutes();

    // No sweep. The stored row still says pending and its claim is still
    // active, but the effective-status rule is what the map applies.
    $freed = collect($this->getJson("/api/v1/events/{$this->event->id}/seats")->json('data'))
        ->firstWhere('id', $this->seats[0]->id);

    expect($freed['status'])->toBe('available');
});

it('keeps the event counts correct with the scheduler never running', function () {
    app(CreateReservation::class)->handle($this->alice, $this->event, [$this->seats[0]->id]);

    $this->getJson("/api/v1/events/{$this->event->id}")->assertJsonPath('data.seats_available', 2);

    travel(31)->minutes();

    $this->getJson("/api/v1/events/{$this->event->id}")->assertJsonPath('data.seats_available', 3);
});

it('lets another user take the seat with the scheduler never running', function () {
    $stale = app(CreateReservation::class)
        ->handle($this->alice, $this->event, [$this->seats[0]->id])
        ->reservation;

    travel(31)->minutes();

    // The write path reclaims inline rather than waiting for the sweep, which
    // is what makes the scheduler optional rather than load-bearing.
    $bob = User::factory()->create();

    $result = app(CreateReservation::class)->handle($bob, $this->event, [$this->seats[0]->id]);

    expect($result->created)->toBeTrue()
        ->and($stale->refresh()->status)->toBe(ReservationStatus::Expired)
        ->and($result->reservation->claims()->active()->count())->toBe(1);
});

it('refuses checkout on a lapsed hold with the scheduler never running', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->alice, $this->event, [$this->seats[0]->id])
        ->reservation;

    travel(31)->minutes();

    // Guards read the effective status too, so an unswept hold cannot be paid.
    $this->actingAs($this->alice)
        ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_expired');
});

it('reports the effective status to the owner with the scheduler never running', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->alice, $this->event, [$this->seats[0]->id])
        ->reservation;

    travel(31)->minutes();

    $this->actingAs($this->alice)
        ->getJson("/api/v1/reservations/{$reservation->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        // The lag is visible rather than hidden: stored still says pending.
        ->assertJsonPath('data.stored_status', 'pending');
});

it('catches up without double-counting when the scheduler returns', function () {
    $stale = app(CreateReservation::class)
        ->handle($this->alice, $this->event, [$this->seats[0]->id])
        ->reservation;

    travel(31)->minutes();

    // Hours of downtime, then the sweep comes back and writes down what was
    // already true. Nothing it does changes any answer given meanwhile.
    travel(6)->hours();

    artisan('reservations:expire')->expectsOutputToContain('expired 1, skipped 0')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(ReservationStatus::Expired)
        ->and($stale->claims()->active()->count())->toBe(0);

    $map = collect($this->getJson("/api/v1/events/{$this->event->id}/seats")->json('data'));

    expect($map->firstWhere('id', $this->seats[0]->id)['status'])->toBe('available');
});

it('does not resurrect a hold the reserve path already reclaimed', function () {
    $stale = app(CreateReservation::class)
        ->handle($this->alice, $this->event, [$this->seats[0]->id])
        ->reservation;

    travel(31)->minutes();

    // Inline reclaim during someone else's reserve, then the sweep returns.
    app(CreateReservation::class)->handle(User::factory()->create(), $this->event, [$this->seats[0]->id]);

    artisan('reservations:expire')->expectsOutputToContain('expired 0, skipped 0')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(ReservationStatus::Expired)
        ->and(Reservation::query()->where('status', ReservationStatus::Pending)->count())->toBe(1);
});
