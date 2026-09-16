<?php

use App\Actions\Reservations\CreateReservation;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Rate limits
|--------------------------------------------------------------------------
|
| Section 8: login 5/min per IP, reservations and checkout 10/min per user.
| The reservation limiter is keyed by user rather than IP because the abuse it
| exists to stop — seat hoarding and provider spam — is committed by a known
| account, not an anonymous one.
|
| The real throttle middleware runs here; nothing is faked.
|
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $this->seats = Seat::factory()->count(30)->for($this->event)->create();
});

/**
 * Which middleware a named route actually carries, as the router sees it.
 *
 * @return list<string>
 */
function middlewareOf(string $name): array
{
    return Route::getRoutes()->getByName($name)->gatherMiddleware();
}

it('wires the documented limiter onto every route that needs one', function () {
    expect(middlewareOf('auth.register'))->toContain('throttle:login')
        ->and(middlewareOf('auth.login'))->toContain('throttle:login')
        ->and(middlewareOf('reservations.store'))->toContain('throttle:reservations', 'auth:sanctum')
        ->and(middlewareOf('reservations.checkout'))->toContain('throttle:reservations', 'auth:sanctum');
});

it('leaves the webhook outside auth with only a per-ip flood guard', function () {
    $middleware = middlewareOf('webhooks.payments');

    // The provider has no token, so the signature is the whole of the
    // security. A per-user limiter would be meaningless here, and the
    // reservations limiter would reject events we need.
    expect($middleware)->toContain('throttle:webhooks')
        ->not->toContain('auth:sanctum')
        ->not->toContain('throttle:reservations');
});

/*
|--------------------------------------------------------------------------
| POST /reservations
|--------------------------------------------------------------------------
*/

it('throttles an eleventh reservation in a minute', function () {
    // Ten distinct holds, each cancelled so the next is not a 409 on the
    // one-pending-per-user rule. The limiter counts requests, not successes.
    foreach (range(0, 9) as $i) {
        $response = $this->actingAs($this->user)->postJson('/api/v1/reservations', [
            'event_id' => $this->event->id,
            'seat_ids' => [$this->seats[$i]->id],
        ]);

        $response->assertCreated();

        $this->actingAs($this->user)->deleteJson("/api/v1/reservations/{$response->json('data.id')}");
    }

    $blocked = $this->actingAs($this->user)->postJson('/api/v1/reservations', [
        'event_id' => $this->event->id,
        'seat_ids' => [$this->seats[10]->id],
    ]);

    $blocked->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests')
        // Only the throttle arm words it this way; the generic HTTP arm does
        // not, so this is what proves the dedicated branch ran.
        ->assertJsonPath('error.message', 'Too many requests.')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']])
        ->assertHeader('Retry-After');

    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        // Refused before any work: the eleventh seat was never claimed.
        ->and(Reservation::query()->where('status', 'pending')->count())->toBe(0);
});

it('counts the reservation limiter per user, not per ip', function () {
    foreach (range(0, 9) as $i) {
        $created = $this->actingAs($this->user)->postJson('/api/v1/reservations', [
            'event_id' => $this->event->id,
            'seat_ids' => [$this->seats[$i]->id],
        ])->assertCreated();

        $this->actingAs($this->user)->deleteJson('/api/v1/reservations/'.$created->json('data.id'));
    }

    $this->actingAs($this->user)->postJson('/api/v1/reservations', [
        'event_id' => $this->event->id,
        'seat_ids' => [$this->seats[10]->id],
    ])->assertStatus(429);

    // Same IP, different account: one user's spending cannot lock out another.
    $this->actingAs(User::factory()->create())->postJson('/api/v1/reservations', [
        'event_id' => $this->event->id,
        'seat_ids' => [$this->seats[11]->id],
    ])->assertCreated();
});

/*
|--------------------------------------------------------------------------
| POST /reservations/{id}/checkout
|--------------------------------------------------------------------------
*/

it('throttles an eleventh checkout in a minute', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, [$this->seats[0]->id])
        ->reservation;

    // Resuming a live session costs a request even though it makes no new
    // attempt: the limiter is there to cap calls, not rows.
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->user)
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
            ->assertOk();
    }

    $blocked = $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$reservation->id}/checkout");

    $blocked->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests')
        ->assertJsonPath('error.message', 'Too many requests.')
        ->assertHeader('Retry-After');

    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Payment::query()->count())->toBe(1);
});

it('shares one budget between reserving and checking out', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, [$this->seats[0]->id])
        ->reservation;

    // Both routes use the `reservations` limiter, so five of each is ten.
    foreach (range(1, 5) as $i) {
        $this->actingAs($this->user)
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
            ->assertOk();
    }

    foreach (range(1, 5) as $i) {
        // Already holding a pending reservation on this event, so these are
        // 409s — still requests, still counted.
        $this->actingAs($this->user)->postJson('/api/v1/reservations', [
            'event_id' => $this->event->id,
            'seat_ids' => [$this->seats[$i]->id],
        ])->assertStatus(409);
    }

    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
        ->assertStatus(429);
});

it('does not throttle reading a reservation', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, [$this->seats[0]->id])
        ->reservation;

    // The frontend polls this after the hosted-checkout redirect, so a limit
    // here would break the documented flow.
    foreach (range(1, 15) as $i) {
        $this->actingAs($this->user)
            ->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertOk();
    }
});
