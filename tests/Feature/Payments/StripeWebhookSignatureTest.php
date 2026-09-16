<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\Drivers\StripeGateway;
use App\Payments\PaymentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const STRIPE_TEST_SECRET = 'whsec_test_secret_for_signature_verification';

beforeEach(function () {
    config([
        'payments.stripe.secret_key' => 'sk_test_unit',
        'payments.stripe.webhook_secret' => STRIPE_TEST_SECRET,
    ]);

    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    $this->reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    // An attempt that was opened against Stripe. The driver is not the
    // default, so this is written directly rather than through checkout.
    $this->attempt = Payment::query()->create([
        'reservation_id' => $this->reservation->id,
        'provider' => 'stripe',
        'provider_ref' => 'cs_test_signature',
        'amount' => (int) $this->reservation->total_amount,
        'currency' => $this->reservation->currency,
        'status' => PaymentStatus::Initiated,
    ]);
});

/**
 * Sign a payload exactly the way Stripe does.
 *
 * The HMAC covers "{timestamp}.{payload}", not the payload alone, which is
 * what stops a captured signature being replayed against different bytes.
 */
function stripeSignature(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
}

function stripePayload(Payment $attempt, ?int $amount = null, ?string $currency = null): string
{
    return json_encode([
        'id' => 'evt_test_'.bin2hex(random_bytes(6)),
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => (string) $attempt->provider_ref,
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total' => $amount ?? (int) $attempt->amount,
                // Stripe sends lowercase; the driver uppercases it so the
                // comparison against our snapshot can be an equality check.
                'currency' => strtolower($currency ?? (string) $attempt->currency),
                'metadata' => ['payment_id' => (string) $attempt->id],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

function postStripeWebhook(string $payload, ?string $signature)
{
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($signature !== null) {
        $headers['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/api/v1/webhooks/payments/stripe', [], [], [], $headers, $payload);
}

/*
|--------------------------------------------------------------------------
| The driver is reachable by path without being the default
|--------------------------------------------------------------------------
*/

it('resolves the stripe driver by path while fake stays the default', function () {
    // The provider is named in the URL, not chosen by us: a deployment can
    // receive Stripe callbacks without Stripe being the configured driver.
    expect(config('payments.driver'))->toBe('fake')
        ->and(app(PaymentManager::class)->driver())->not->toBeInstanceOf(StripeGateway::class)
        ->and(app(PaymentManager::class)->driverFor('stripe'))->toBeInstanceOf(StripeGateway::class);
});

/*
|--------------------------------------------------------------------------
| A correctly signed event
|--------------------------------------------------------------------------
*/

it('accepts a correctly signed event and confirms by metadata', function () {
    $payload = stripePayload($this->attempt);

    postStripeWebhook($payload, stripeSignature($payload, STRIPE_TEST_SECRET))
        ->assertNoContent();

    $this->attempt->refresh();
    $this->reservation->refresh();

    expect($this->attempt->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->attempt->processed_at)->not->toBeNull()
        ->and($this->reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->claims()->active()->count())->toBe(2);

    $record = WebhookEvent::query()->sole();

    expect($record->provider)->toBe('stripe')
        ->and($record->processed_at)->not->toBeNull();
});

it('resolves the attempt from metadata rather than the session id', function () {
    // The session id in the payload is one we have never stored, so only the
    // payment_id in metadata can find this attempt.
    $this->attempt->forceFill(['provider_ref' => null])->save();

    $payload = json_encode([
        'id' => 'evt_metadata_only',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_never_stored',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total' => (int) $this->attempt->amount,
                'currency' => strtolower((string) $this->attempt->currency),
                'metadata' => ['payment_id' => (string) $this->attempt->id],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    postStripeWebhook($payload, stripeSignature($payload, STRIPE_TEST_SECRET))->assertNoContent();

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->attempt->provider_ref)->toBe('cs_test_never_stored')
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

it('refuses to confirm when the amount does not match the attempt', function () {
    $payload = stripePayload($this->attempt, amount: 1);

    postStripeWebhook($payload, stripeSignature($payload, STRIPE_TEST_SECRET))->assertNoContent();

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Mismatched)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| Signatures that do not verify
|--------------------------------------------------------------------------
*/

it('rejects an event signed with the wrong secret', function () {
    $payload = stripePayload($this->attempt);

    postStripeWebhook($payload, stripeSignature($payload, 'whsec_not_the_secret'))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_signature')
        // Only this branch says this; the renderer's generic 400 arm does not.
        ->assertJsonPath('error.message', 'The webhook signature could not be verified.');

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('rejects a body altered after it was signed', function () {
    $payload = stripePayload($this->attempt);
    $signature = stripeSignature($payload, STRIPE_TEST_SECRET);

    // The signature covers the exact bytes, so editing them invalidates it.
    $tampered = str_replace('"payment_status":"paid"', '"payment_status":"paid" ', $payload);

    postStripeWebhook($tampered, $signature)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_signature');

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated);
});

it('rejects a signature whose timestamp is outside the tolerance', function () {
    $payload = stripePayload($this->attempt);

    // Correct HMAC, but for a timestamp far in the past: this is what stops a
    // captured request being replayed days later.
    $stale = stripeSignature($payload, STRIPE_TEST_SECRET, timestamp: time() - 86_400);

    postStripeWebhook($payload, $stale)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_signature');

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated);
});

it('rejects a request with no signature header at all', function () {
    postStripeWebhook(stripePayload($this->attempt), null)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_signature');

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('rejects an event when no webhook secret is configured', function () {
    config(['payments.stripe.webhook_secret' => '']);

    $payload = stripePayload($this->attempt);

    // Fail closed: an unconfigured verifier must never wave events through.
    postStripeWebhook($payload, stripeSignature($payload, STRIPE_TEST_SECRET))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_signature');

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated);
});
