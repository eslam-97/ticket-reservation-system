<?php

use App\Actions\Reservations\CancelReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSeat;
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

    $this->reservation = Reservation::query()->create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'status' => ReservationStatus::Pending,
        'total_amount' => (int) $this->seats->sum('price'),
        'currency' => $this->event->currency,
        'expires_at' => now()->addMinutes(30),
    ]);

    foreach ($this->seats as $seat) {
        ReservationSeat::query()->create([
            'reservation_id' => $this->reservation->id,
            'seat_id' => $seat->id,
            'unit_price' => $seat->price,
        ]);
    }
});

function checkout(?User $as = null, ?Reservation $reservation = null)
{
    $as ??= test()->user;
    $reservation ??= test()->reservation;

    return test()->actingAs($as)->postJson("/api/v1/reservations/{$reservation->id}/checkout");
}

/*
|--------------------------------------------------------------------------
| 1. The ordinary path
|--------------------------------------------------------------------------
*/

it('opens an attempt and a hosted session', function () {
    $response = checkout()->assertOk();

    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing(['payment_id', 'checkout_url', 'provider_ref', 'session_expires_at']);

    $attempt = Payment::query()->sole();

    expect($attempt->status)->toBe(PaymentStatus::Initiated)
        // Server-side from the frozen snapshot; the client sent no amount.
        ->and($attempt->amount)->toBe((int) $this->reservation->total_amount)
        ->and($attempt->currency)->toBe($this->reservation->currency)
        ->and($attempt->provider)->toBe('fake')
        ->and($attempt->provider_ref)->toStartWith('fake_cs_')
        ->and($attempt->raw_payload['checkout_url'])->toBe($response->json('data.checkout_url'))
        ->and($attempt->raw_payload['session_expires_at'])->toBeString();

    $response->assertJsonPath('data.payment_id', $attempt->id)
        ->assertJsonPath('data.provider_ref', $attempt->provider_ref);

    // The URL comes from config, never from the client.
    expect($response->json('data.checkout_url'))
        ->toStartWith((string) config('payments.fake.checkout_base_url'));
});

/*
|--------------------------------------------------------------------------
| 2. Resuming
|--------------------------------------------------------------------------
*/

it('resumes the live session instead of opening a second one', function () {
    $first = checkout()->assertOk();
    $second = checkout()->assertOk();

    expect($second->json('data.payment_id'))->toBe($first->json('data.payment_id'))
        ->and($second->json('data.checkout_url'))->toBe($first->json('data.checkout_url'))
        ->and($second->json('data.provider_ref'))->toBe($first->json('data.provider_ref'))
        ->and(Payment::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 3. The timeout case
|--------------------------------------------------------------------------
*/

it('recovers the session the provider created before the call timed out', function () {
    FakeGateway::timeoutNextCreate();

    checkout()
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'payment_provider_unavailable')
        ->assertHeader('Retry-After', '5');

    $attempt = Payment::query()->sole();

    // The attempt survives with no reference: the session exists at the
    // provider but we never heard which one it was.
    expect($attempt->status)->toBe(PaymentStatus::Initiated)
        ->and($attempt->provider_ref)->toBeNull()
        ->and($attempt->attempts)->toBe(1);

    $recovered = checkout()->assertOk();

    // Same attempt, therefore the same idempotency key, therefore the session
    // the provider already made rather than a second one.
    expect($recovered->json('data.payment_id'))->toBe($attempt->id)
        ->and(Payment::query()->count())->toBe(1);

    $attempt->refresh();

    expect($attempt->provider_ref)->toBe($recovered->json('data.provider_ref'))
        ->and(FakeGateway::session($attempt->provider_ref)['payment_id'])->toBe($attempt->id);
});

/*
|--------------------------------------------------------------------------
| 4. Transient versus definitive provider failures
|--------------------------------------------------------------------------
*/

it('answers a transient provider failure with 503 and keeps the attempt open', function () {
    FakeGateway::failNextCreateTransient();

    checkout()
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'payment_provider_unavailable')
        ->assertJsonPath('error.message', 'The payment provider is temporarily unavailable. Please try again.')
        ->assertHeader('Retry-After', '5');

    $attempt = Payment::query()->sole();

    expect($attempt->status)->toBe(PaymentStatus::Initiated)
        ->and($attempt->provider_ref)->toBeNull();

    // Retryable, and the retry works.
    checkout()->assertOk();
});

it('answers a definitive rejection with 502, no Retry-After, and a failed attempt', function () {
    FakeGateway::rejectNextCreate('amount_too_small');

    $response = checkout()
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'payment_provider_rejected')
        ->assertJsonPath('error.details.failure_code', 'amount_too_small');

    // Retrying will never help, so the client is deliberately not told to.
    expect($response->headers->get('Retry-After'))->toBeNull();

    $attempt = Payment::query()->sole();

    expect($attempt->status)->toBe(PaymentStatus::Failed)
        ->and($attempt->failure_code)->toBe('amount_too_small');
});

/*
|--------------------------------------------------------------------------
| 5. The attempt cap
|--------------------------------------------------------------------------
*/

it('refuses checkout once the attempt cap is spent', function () {
    $max = (int) config('payments.checkout_max_attempts');

    for ($i = 0; $i < $max; $i++) {
        FakeGateway::rejectNextCreate('card_declined');
        checkout()->assertStatus(502);
    }

    expect(Payment::query()->where('status', PaymentStatus::Failed)->count())->toBe($max);

    checkout()
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'checkout_attempts_exceeded')
        ->assertJsonPath('error.details.max_attempts', $max)
        ->assertJsonPath('error.details.attempts', $max);

    // The cap limits provider calls, not the hold: no extra row was made.
    expect(Payment::query()->count())->toBe($max);
});

/*
|--------------------------------------------------------------------------
| 6. Reservations that are no longer payable
|--------------------------------------------------------------------------
*/

it('refuses checkout on a hold that has lapsed', function () {
    travel(31)->minutes();

    checkout()
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_expired')
        ->assertJsonPath('error.details.status', 'expired');

    expect(Payment::query()->count())->toBe(0);
});

it('refuses checkout on a cancelled reservation', function () {
    app(CancelReservation::class)->handle($this->user, $this->reservation);

    checkout()
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_not_pending')
        ->assertJsonPath('error.details.status', 'cancelled');
});

/*
|--------------------------------------------------------------------------
| 7. The checkout-versus-cancel race: the second finisher cleans up
|--------------------------------------------------------------------------
*/

it('closes the session and returns 410 when a cancel lands mid-checkout', function () {
    // The cancel runs after T1 has committed an attempt with no provider_ref
    // and after the provider has created the session, but before T2 stores
    // the reference. Cancel therefore finds nothing to close and skips its
    // own provider call — leaving a live page for a cancelled reservation.
    FakeGateway::onNextCreate(function () {
        app(CancelReservation::class)->handle($this->user, $this->reservation);
    });

    checkout()
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'reservation_not_pending')
        ->assertJsonPath('error.details.status', 'cancelled');

    $attempt = Payment::query()->sole();
    $this->reservation->refresh();

    expect($this->reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($this->reservation->claims()->active()->count())->toBe(0)
        // T2 stored the reference even though the reservation was gone: it is
        // the only way to close the session.
        ->and($attempt->provider_ref)->toStartWith('fake_cs_')
        // Checkout, as the second finisher, ran the close routine.
        ->and($attempt->status)->toBe(PaymentStatus::Cancelled)
        ->and(FakeGateway::session($attempt->provider_ref)['status'])->toBe('expired');
});

it('leaves the attempt open when the provider will not confirm the close', function () {
    FakeGateway::onNextCreate(function () {
        app(CancelReservation::class)->handle($this->user, $this->reservation);
    });
    FakeGateway::failNextCancel();

    checkout()->assertStatus(410)->assertJsonPath('error.code', 'reservation_not_pending');

    $attempt = Payment::query()->sole();

    // The session might still be paid, so writing `cancelled` now would force
    // a backward edge later. It stays initiated for reconcile to settle.
    expect($attempt->status)->toBe(PaymentStatus::Initiated)
        ->and($attempt->provider_ref)->toStartWith('fake_cs_')
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

/*
|--------------------------------------------------------------------------
| 8. Ownership
|--------------------------------------------------------------------------
*/

it('refuses checkout on someone else\'s reservation', function () {
    checkout(as: User::factory()->create())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect(Payment::query()->count())->toBe(0);
});

it('requires authentication', function () {
    $this->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
