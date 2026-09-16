<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Payments\Drivers\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    $this->reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;
});

function cancel(?User $as = null, ?Reservation $reservation = null)
{
    $as ??= test()->user;
    $reservation ??= test()->reservation;

    return test()->actingAs($as)->deleteJson("/api/v1/reservations/{$reservation->id}");
}

function openCheckout(): Payment
{
    test()->actingAs(test()->user)
        ->postJson('/api/v1/reservations/'.test()->reservation->id.'/checkout')
        ->assertOk();

    return Payment::query()->where('reservation_id', test()->reservation->id)->sole();
}

it('cancels a live hold and frees its seats', function () {
    $response = cancel()->assertOk();

    $response->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.stored_status', 'cancelled');

    $this->reservation->refresh();

    expect($this->reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($this->reservation->claims()->active()->count())->toBe(0)
        // Released rows stay as history.
        ->and($this->reservation->claims()->count())->toBe(2);

    // Every released claim is reported, so the client can see what happened.
    expect(collect($response->json('data.seats'))->pluck('released_at')->filter())->toHaveCount(2);

    $map = collect($this->getJson("/api/v1/events/{$this->event->id}/seats")->json('data'));

    expect($map->whereIn('id', $this->seats->pluck('id'))->pluck('status')->unique()->all())
        ->toBe(['available']);
});

it('refuses to cancel twice', function () {
    cancel()->assertOk();

    cancel()
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_not_pending')
        ->assertJsonPath('error.details.status', 'cancelled');
});

it('refuses to cancel a confirmed reservation', function () {
    $this->reservation->transitionTo(ReservationStatus::Confirmed);

    // Cancelling a confirmed reservation implies refunds, which is out of
    // scope and stated as such.
    cancel()
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_not_pending')
        ->assertJsonPath('error.details.status', 'confirmed');
});

it('refuses to cancel a hold that has already lapsed', function () {
    travel(31)->minutes();

    cancel()
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_expired')
        ->assertJsonPath('error.details.status', 'expired');
});

it('refuses to cancel someone else\'s reservation', function () {
    cancel(as: User::factory()->create())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('requires authentication', function () {
    $this->deleteJson("/api/v1/reservations/{$this->reservation->id}")
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

/*
|--------------------------------------------------------------------------
| Closing the hosted page the user walked away from
|--------------------------------------------------------------------------
*/

it('closes an open session and marks the attempt cancelled', function () {
    $attempt = openCheckout();

    cancel()->assertOk();

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::Cancelled)
        ->and($attempt->raw_payload['cancel'])->toBe('expired')
        // The page dies immediately, rather than sitting there payable.
        ->and(FakeGateway::session($attempt->provider_ref)['status'])->toBe('expired');
});

it('leaves the attempt open when the provider will not confirm the close', function () {
    $attempt = openCheckout();

    FakeGateway::failNextCancel();

    cancel()->assertOk();

    // `cancelled` on a payment means the provider confirmed the session can
    // no longer complete. It has not, so writing it now would force a
    // backward edge if the session is paid anyway.
    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($this->reservation->claims()->active()->count())->toBe(0);
});

it('makes no provider call for an attempt that never got a reference', function () {
    FakeGateway::timeoutNextCreate();

    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertStatus(503);

    $attempt = Payment::query()->sole();

    expect($attempt->provider_ref)->toBeNull();

    cancel()->assertOk();

    // Nothing to close yet: checkout T2 or reconcile settles this one.
    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('frees the seats for another user immediately', function () {
    cancel()->assertOk();

    $bob = User::factory()->create();

    $bobsHold = app(CreateReservation::class)
        ->handle($bob, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    expect($bobsHold->claims()->active()->count())->toBe(2)
        ->and($bobsHold->status)->toBe(ReservationStatus::Pending);
});
