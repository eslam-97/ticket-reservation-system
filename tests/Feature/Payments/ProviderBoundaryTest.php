<?php

use App\Actions\Payments\CloseOpenAttempt;
use App\Actions\Reservations\CreateReservation;
use App\Contracts\PaymentGateway;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\Drivers\FakeGateway;
use App\Payments\Drivers\StripeGateway;
use App\Payments\DTO\CancelResult;
use App\Payments\DTO\CheckoutSession;
use App\Payments\FakeWebhookSigner;
use App\Payments\PaymentManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The provider is part of an attempt's identity
|--------------------------------------------------------------------------
|
| Two separate boundaries, both of which used to leak:
|
|  - acting on an existing attempt must use *that attempt's* provider, never
|    whatever config currently names; and
|  - an event arriving from one provider must never resolve another
|    provider's attempt, however valid its signature.
|
| Creating a *new* attempt is different and deliberately still uses the
| configured default — that is what `provider` on the row records.
|
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    $this->reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;
});

/**
 * An open attempt recorded against `$provider`, whatever the config says.
 */
function attemptOn(string $provider, string $ref): Payment
{
    return Payment::query()->create([
        'reservation_id' => test()->reservation->id,
        'provider' => $provider,
        'provider_ref' => $ref,
        'amount' => (int) test()->reservation->total_amount,
        'currency' => test()->reservation->currency,
        'status' => PaymentStatus::Initiated,
    ]);
}

/*
|--------------------------------------------------------------------------
| Closing an attempt uses the attempt's provider
|--------------------------------------------------------------------------
*/

it('closes a stripe attempt through stripe even while fake is the default', function () {
    $attempt = attemptOn('stripe', 'cs_test_live_session');

    expect(config('payments.driver'))->toBe('fake');

    // A stand-in for Stripe, so the assertion is about *which driver was
    // asked* rather than about the network. It answers with a reason the
    // fake never produces for an unknown session, which is what makes the
    // two distinguishable: routed to the fake, this attempt would come back
    // `not_found` instead.
    $stripe = Mockery::mock(PaymentGateway::class);
    $stripe->shouldReceive('cancelCheckout')
        ->once()
        ->andReturn(new CancelResult(true, CancelResult::EXPIRED));

    app(PaymentManager::class)->extend('stripe', fn (): PaymentGateway => $stripe);

    app(CloseOpenAttempt::class)->handle($attempt);

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::Cancelled)
        ->and($attempt->raw_payload['cancel'])->toBe(CancelResult::EXPIRED);
});

it('never asks the fake driver about a stripe attempt', function () {
    // The consequence if it did: the fake reports an unknown session closed,
    // `cancelled` is terminal, and a real payment landing afterwards is
    // ignored — money taken, no seat, no refund.
    $attempt = attemptOn('stripe', 'cs_test_live_session');

    $stripe = Mockery::mock(PaymentGateway::class);
    $stripe->shouldReceive('cancelCheckout')->once()->andReturn(new CancelResult(false, CancelResult::PROVIDER_ERROR));

    app(PaymentManager::class)->extend('stripe', fn (): PaymentGateway => $stripe);

    app(CloseOpenAttempt::class)->handle($attempt);

    // Unconfirmed, so the attempt stays open for the webhook to settle.
    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($attempt->raw_payload['cancel'] ?? null)->toBeNull();
});

it('still closes a fake attempt through the fake driver', function () {
    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertOk();

    $attempt = Payment::query()->where('reservation_id', $this->reservation->id)->sole();

    expect($attempt->provider)->toBe('fake');

    app(CloseOpenAttempt::class)->handle($attempt);

    // The ordinary path is untouched: the fake knows this session and
    // confirms it is closed.
    expect($attempt->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($attempt->raw_payload['cancel'])->toBe('expired');
});

it('records the configured default on a newly created attempt', function () {
    // Creating is the one place the configured driver is right: it is what
    // `provider` on the row comes to mean.
    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertOk();

    expect(Payment::query()->sole()->provider)->toBe(config('payments.driver'))
        ->and(app(PaymentManager::class)->driverFor('fake'))->toBeInstanceOf(FakeGateway::class)
        ->and(app(PaymentManager::class)->driverFor('stripe'))->toBeInstanceOf(StripeGateway::class);
});

it('opens the session at the provider the reused attempt belongs to', function () {
    // The timeout case: an attempt exists, the provider created a session,
    // but no reference ever reached us. Checkout reuses that attempt so the
    // same idempotency key replays the original session — which only works
    // if the key is replayed at the provider that holds it. Resolving from
    // config here would open a second session somewhere else entirely.
    $attempt = Payment::query()->create([
        'reservation_id' => $this->reservation->id,
        'provider' => 'stripe',
        'provider_ref' => null,
        'amount' => (int) $this->reservation->total_amount,
        'currency' => $this->reservation->currency,
        'status' => PaymentStatus::Initiated,
    ]);

    expect(config('payments.driver'))->toBe('fake');

    $stripe = Mockery::mock(PaymentGateway::class);
    $stripe->shouldReceive('createCheckout')
        ->once()
        ->andReturn(new CheckoutSession(
            providerRef: 'cs_from_the_stripe_double',
            url: 'https://checkout.stripe.test/cs_from_the_stripe_double',
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ));

    app(PaymentManager::class)->extend('stripe', fn (): PaymentGateway => $stripe);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertOk();

    // The fake would have answered with a `fake_cs_` reference of its own.
    $response->assertJsonPath('data.payment_id', $attempt->id)
        ->assertJsonPath('data.provider_ref', 'cs_from_the_stripe_double');

    expect($attempt->refresh()->provider_ref)->toBe('cs_from_the_stripe_double')
        ->and($attempt->provider)->toBe('stripe')
        // Reused, not replaced: one attempt, one idempotency key.
        ->and(Payment::query()->count())->toBe(1);
});

it('opens the session at the configured default for a brand-new attempt', function () {
    // The other half of the invariant: config chooses who takes a *new*
    // payment, and that choice is what `provider` on the row then records.
    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertOk()
        ->assertJsonPath('data.provider_ref', fn (string $ref): bool => str_starts_with($ref, 'fake_cs_'));

    expect(Payment::query()->sole()->provider)->toBe('fake');
});

/*
|--------------------------------------------------------------------------
| A webhook cannot reach another provider's attempt
|--------------------------------------------------------------------------
*/

it('will not let a fake-signed event settle a stripe attempt', function () {
    $attempt = attemptOn('stripe', 'cs_test_not_ours');

    $body = [
        'id' => 'evt_cross_provider',
        'type' => 'payment.succeeded',
        'data' => [
            // A real payment id — but one belonging to a Stripe attempt.
            'payment_id' => (string) $attempt->id,
            'provider_ref' => $attempt->provider_ref,
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency,
        ],
    ];

    $signed = app(FakeWebhookSigner::class)->sign($body);

    // Correctly signed *for the fake provider*, and posted to the fake path.
    $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
        'HTTP_X_FAKE_SIGNATURE' => $signed['headers']['X-Fake-Signature'],
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $signed['body'])->assertNoContent();

    // Acknowledged as an orphan — the attempt is not the fake's to settle.
    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

it('will not match another provider reference that happens to collide', function () {
    // provider_ref is only unique within a provider, so the same string can
    // legitimately exist twice. Without the provider filter, a lookup by ref
    // alone could land on the wrong row.
    $stripe = attemptOn('stripe', 'cs_shared_ref');

    $body = [
        'id' => 'evt_ref_collision',
        'type' => 'payment.succeeded',
        // No payment_id at all, so the ref is the only handle.
        'data' => [
            'provider_ref' => 'cs_shared_ref',
            'amount' => (int) $stripe->amount,
            'currency' => $stripe->currency,
        ],
    ];

    $signed = app(FakeWebhookSigner::class)->sign($body);

    $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
        'HTTP_X_FAKE_SIGNATURE' => $signed['headers']['X-Fake-Signature'],
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $signed['body'])->assertNoContent();

    expect($stripe->refresh()->status)->toBe(PaymentStatus::Initiated);
});

it('still settles an attempt from its own provider', function () {
    // The guard must not break the ordinary path.
    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertOk();

    $attempt = Payment::query()->where('reservation_id', $this->reservation->id)->sole();

    $signed = app(FakeWebhookSigner::class)->sign([
        'id' => 'evt_same_provider',
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => (string) $attempt->id,
            'provider_ref' => $attempt->provider_ref,
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency,
        ],
    ]);

    $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
        'HTTP_X_FAKE_SIGNATURE' => $signed['headers']['X-Fake-Signature'],
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $signed['body'])->assertNoContent();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});
