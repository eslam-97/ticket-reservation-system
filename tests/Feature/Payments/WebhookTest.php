<?php

use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\CreateReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\Drivers\FakeGateway;
use App\Payments\FakeWebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    $this->reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $this->attempt = null;
});

/**
 * Take the reservation through a real checkout so an attempt exists.
 */
function startCheckout(?Reservation $reservation = null): Payment
{
    $reservation ??= test()->reservation;

    test()->actingAs($reservation->user)
        ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
        ->assertOk();

    return Payment::query()->where('reservation_id', $reservation->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
}

/**
 * POST a signed webhook the way the provider would, over real HTTP.
 */
function sendWebhook(array $body, ?string $signature = null, string $provider = 'fake')
{
    $signed = app(FakeWebhookSigner::class)->sign($body);

    return test()->call(
        'POST',
        "/api/v1/webhooks/payments/{$provider}",
        [],
        [],
        [],
        [
            'HTTP_X_FAKE_SIGNATURE' => $signature ?? $signed['headers']['X-Fake-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $signed['body'],
    );
}

function successEventFor(Payment $attempt, ?int $amount = null, ?string $currency = null, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.Str::ulid(),
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => (string) $attempt->id,
            'provider_ref' => $attempt->provider_ref,
            'amount' => $amount ?? (int) $attempt->amount,
            'currency' => $currency ?? $attempt->currency,
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| 9 & 10. The ordinary path, and redelivery
|--------------------------------------------------------------------------
*/

it('confirms the reservation on a successful payment', function () {
    $attempt = startCheckout();

    sendWebhook(successEventFor($attempt))->assertNoContent();

    $attempt->refresh();
    $this->reservation->refresh();

    expect($attempt->status)->toBe(PaymentStatus::Succeeded)
        ->and($attempt->processed_at)->not->toBeNull()
        ->and($this->reservation->status)->toBe(ReservationStatus::Confirmed)
        // Confirming does not disturb the claims; they are what hold the seats.
        ->and($this->reservation->claims()->active()->count())->toBe(2);

    $map = collect($this->getJson("/api/v1/events/{$this->event->id}/seats")->json('data'));

    expect($map->whereIn('id', $this->seats->pluck('id'))->pluck('status')->unique()->all())
        ->toBe(['sold']);
});

it('ignores a redelivery of the same event', function () {
    $attempt = startCheckout();
    $body = successEventFor($attempt, eventId: 'evt_fixed');

    sendWebhook($body)->assertNoContent();

    $confirmedAt = $this->reservation->refresh()->updated_at;

    // Same event id: the unique index makes this a no-op, not an error.
    sendWebhook($body)->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->updated_at->equalTo($confirmedAt))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 11. A failed payment leaves the hold alone
|--------------------------------------------------------------------------
*/

it('leaves the hold payable after a failed payment', function () {
    $attempt = startCheckout();

    sendWebhook([
        'id' => 'evt_failed',
        'type' => 'payment.failed',
        'data' => ['payment_id' => (string) $attempt->id, 'provider_ref' => $attempt->provider_ref],
    ])->assertNoContent();

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::Failed)
        ->and($attempt->failure_code)->toBe('payment_failed')
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($this->reservation->claims()->active()->count())->toBe(2);

    // Expiry is what frees the seats, so the user can try again until then.
    $second = startCheckout();

    expect($second->id)->not->toBe($attempt->id)
        ->and(Payment::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 12. Forged signatures
|--------------------------------------------------------------------------
*/

it('rejects a tampered body without recording or changing anything', function () {
    $attempt = startCheckout();
    $body = successEventFor($attempt);

    $signed = app(FakeWebhookSigner::class)->sign($body);

    $response = $this->call(
        'POST',
        '/api/v1/webhooks/payments/fake',
        [],
        [],
        [],
        [
            'HTTP_X_FAKE_SIGNATURE' => $signed['headers']['X-Fake-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        str_replace('payment.succeeded', 'payment.failed', $signed['body']),
    );

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_signature')
        // Distinguishes this from the renderer's generic 400 arm.
        ->assertJsonPath('error.message', 'The webhook signature could not be verified.');

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);

    // The rejected body is never echoed back: not the payload, not the ids
    // inside it, not the signature that failed. The response is the envelope
    // and nothing else.
    expect($response->json())->toBe([
        'error' => [
            'code' => 'invalid_signature',
            'message' => 'The webhook signature could not be verified.',
            'details' => [],
        ],
    ]);

    expect($response->getContent())
        ->not->toContain((string) $attempt->id)
        ->not->toContain((string) $attempt->provider_ref)
        ->not->toContain('amount')
        ->not->toContain('payment_status')
        ->not->toContain($signed['headers']['X-Fake-Signature']);
});

/*
|--------------------------------------------------------------------------
| 13. Money that does not match
|--------------------------------------------------------------------------
*/

it('marks a mismatched amount without confirming anything', function () {
    $attempt = startCheckout();

    sendWebhook(successEventFor($attempt, amount: 1))->assertNoContent();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Mismatched)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('marks a mismatched currency without confirming anything', function () {
    $attempt = startCheckout();

    sendWebhook(successEventFor($attempt, currency: 'USD'))->assertNoContent();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Mismatched)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| 14 & 15. Paying after the hold has lapsed
|--------------------------------------------------------------------------
*/

it('confirms a late payment when the seats are still free', function () {
    $attempt = startCheckout();

    travel(31)->minutes();

    // No sweep has run: the row still says pending, its claims are still
    // active, and the effective-status rule is what calls it expired.
    expect($this->reservation->refresh()->isEffectivelyExpired())->toBeTrue();

    sendWebhook(successEventFor($attempt))->assertNoContent();

    $this->reservation->refresh();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->claims()->active()->count())->toBe(2);
});

it('confirms a late payment with fresh claims after the sweep has run', function () {
    $attempt = startCheckout();
    $originalPrices = $this->reservation->claims()->pluck('unit_price', 'seat_id');

    travel(31)->minutes();

    // The real sweep, not a stand-in: it materialises the expiry and releases
    // the claims, which is the state confirmLate has to reinstate from.
    artisan('reservations:expire')->expectsOutputToContain('expired 1')->assertSuccessful();

    $this->reservation->refresh();

    expect($this->reservation->status)->toBe(ReservationStatus::Expired)
        ->and($this->reservation->claims()->active()->count())->toBe(0);

    sendWebhook(successEventFor($attempt))->assertNoContent();

    $this->reservation->refresh();

    expect($this->reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->claims()->active()->count())->toBe(2)
        // History is not rewritten: the released rows stay released and new
        // ones are inserted alongside them.
        ->and($this->reservation->claims()->whereNotNull('released_at')->count())->toBe(2)
        ->and($this->reservation->claims()->count())->toBe(4);

    // The fresh claims carry the original snapshot, not today's price.
    $this->reservation->claims()->whereNull('released_at')->get()->each(
        fn (ReservationSeat $claim) => expect((int) $claim->unit_price)
            ->toBe((int) $originalPrices[$claim->seat_id])
    );
});

it('refunds a late payment when the seat has been retaken', function () {
    $attempt = startCheckout();

    travel(31)->minutes();

    // Another user reclaims the lapsed hold and takes the seats.
    $bob = User::factory()->create();
    $bobsHold = app(CreateReservation::class)
        ->handle($bob, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    sendWebhook(successEventFor($attempt))->assertNoContent();

    $this->reservation->refresh();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::RefundPending)
        ->and($attempt->next_retry_at)->not->toBeNull()
        // Refunding is worse for the customer than a seat, but the seat is
        // gone: the reservation stays where the reclaim left it.
        ->and($this->reservation->status)->toBe(ReservationStatus::Expired)
        ->and($this->reservation->claims()->active()->count())->toBe(0)
        // Bob is untouched.
        ->and($bobsHold->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($bobsHold->claims()->active()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 16 & 17. Paying after a cancellation
|--------------------------------------------------------------------------
*/

it('refunds a payment that lands after the user cancelled', function () {
    $attempt = startCheckout();

    // The provider will not confirm the close, so the attempt stays open and
    // the hosted page can still be paid.
    FakeGateway::failNextCancel();
    app(CancelReservation::class)->handle($this->user, $this->reservation);

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated);

    sendWebhook(successEventFor($attempt))->assertNoContent();

    $this->reservation->refresh();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::RefundPending)
        // Cancellation is final: nothing resurrects it.
        ->and($this->reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($this->reservation->claims()->active()->count())->toBe(0);
});

it('ignores a payment for an attempt the provider already confirmed cancelled', function () {
    $attempt = startCheckout();

    app(CancelReservation::class)->handle($this->user, $this->reservation);

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Cancelled);

    sendWebhook(successEventFor($attempt))->assertNoContent();

    // Terminal means terminal. No backward edge, no refund queued.
    expect($attempt->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

/*
|--------------------------------------------------------------------------
| 18. A webhook that beats provider_ref
|--------------------------------------------------------------------------
*/

it('resolves the attempt from metadata when provider_ref was never stored', function () {
    FakeGateway::timeoutNextCreate();

    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertStatus(503);

    $attempt = Payment::query()->sole();

    expect($attempt->provider_ref)->toBeNull();

    // The user paid on a page we never recorded the reference for. Our own
    // ULID went into the metadata before the call, so the attempt is findable.
    sendWebhook([
        'id' => 'evt_early',
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => (string) $attempt->id,
            'provider_ref' => 'fake_cs_never_seen',
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency,
        ],
    ])->assertNoContent();

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::Succeeded)
        ->and($attempt->provider_ref)->toBe('fake_cs_never_seen')
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

/*
|--------------------------------------------------------------------------
| 19 & 20. Events we cannot place, and providers we do not have
|--------------------------------------------------------------------------
*/

it('acknowledges an event for a payment it has never heard of', function () {
    sendWebhook([
        'id' => 'evt_orphan',
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => (string) Str::ulid(),
            'provider_ref' => 'fake_cs_orphan',
            'amount' => 100,
            'currency' => 'EGP',
        ],
    ])->assertNoContent();

    // Acknowledged and recorded: a 500 here would make the provider redeliver
    // an event we will never be able to place.
    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('acknowledges an event type it does not act on', function () {
    $attempt = startCheckout();

    sendWebhook([
        'id' => 'evt_noise',
        'type' => 'customer.updated',
        'data' => ['payment_id' => (string) $attempt->id],
    ])->assertNoContent();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('returns 404 for a provider it does not implement', function () {
    $response = sendWebhook(['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => []], provider: 'paymob');

    $response->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        // Only this branch names the provider; the generic 404 arm does not.
        ->assertJsonPath('error.message', 'Unknown payment provider [paymob].')
        ->assertJsonPath('error.details.provider', 'paymob');

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('needs no authentication', function () {
    $attempt = startCheckout();

    // No actingAs, no token: the signature is the whole of the security.
    sendWebhook(successEventFor($attempt))->assertNoContent();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Succeeded);
});
